package com.example.contactsync.cep

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CepLogicTest {

    /** Фрагмент profiles.json, как его пишет CEP для macOS (JSONEncoder, ключи по алфавиту). */
    private val macStore = """
        {
          "chains" : [
            {
              "blockIPv6" : true,
              "dns" : [ "192.168.1.119", "1.1.1.1" ],
              "hops" : [ "A1", "B2", "GONE" ],
              "id" : "C1",
              "name" : "ППР"
            }
          ],
          "profiles" : [
            {
              "authGroup" : "", "config" : "", "id" : "A1", "ignoreCertErrors" : false,
              "name" : "VLESS 1", "openconnectFlavor" : "anyconnect", "password" : "", "proto" : "vless",
              "routes" : "", "server" : "", "serverCert" : "", "uri" : "vless://u@host:443?type=grpc", "username" : ""
            },
            {
              "id" : "B2", "name" : "OpenConnect 1", "proto" : "openconnect",
              "server" : "https://94.103.9.172:4443", "username" : "user", "password" : "secret",
              "serverCert" : "pin-sha256:abc=", "routes" : "192.168.1.0/24"
            },
            { "id" : "P3", "name" : "Старый PPTP", "proto" : "pptp" }
          ]
        }
    """.trimIndent()

    @Test
    fun `decode reads mac store, skips unknown protocols and dangling hops`() {
        val store = CepLogic.decode(macStore)

        assertEquals(listOf("A1", "B2"), store.profiles.map { it.id })
        assertEquals(listOf("A1", "B2"), store.chains.single().hops)
        assertEquals("secret", store.profiles[1].password)
        assertEquals(listOf("192.168.1.119", "1.1.1.1"), store.chains.single().dns)
    }

    @Test
    fun `encode keeps the mac format readable back`() {
        val store = CepLogic.decode(macStore)

        assertEquals(store, CepLogic.decode(CepLogic.encode(store)))
    }

    @Test
    fun `argon profile survives json round trip`() {
        val profile = CepProfile(
            id = "A4",
            name = "Argon",
            proto = "argon4",
            server = "argon.example.com:51820",
            password = "master",
            clientID = "phone",
            clientSecret = "owner",
        )
        val store = CepStore(profiles = listOf(profile), chains = listOf(CepChain(name = "chain", hops = listOf("A4"))))

        assertEquals(store, CepLogic.decode(CepLogic.encode(store)))
        assertEquals(VpnProtocol.ARGON4, CepLogic.decode(CepLogic.encode(store)).profiles.single().protocol)
    }

    @Test
    fun `merge replaces items with the same id and appends new ones`() {
        val current = CepStore(
            profiles = listOf(CepProfile(id = "A1", name = "старый", proto = "vless"), CepProfile(id = "L", name = "свой", proto = "hysteria2")),
            chains = listOf(CepChain(id = "C1", name = "старая")),
        )
        val merged = CepLogic.merge(current, CepLogic.decode(macStore))

        assertEquals(listOf("L", "A1", "B2"), merged.profiles.map { it.id })
        assertEquals("VLESS 1", merged.profiles.first { it.id == "A1" }.name)
        assertEquals(listOf("ППР"), merged.chains.map { it.name })
    }

    @Test
    fun `parse links names profiles by label or host and rejects other schemes`() {
        val parsed = CepLogic.parseLinks(
            """
            vless://uuid@cube.example.com:443?security=reality#Россия
            hy2://pass@hy.example.com:443
            ss://abc@host:8388
            """.trimIndent(),
        )

        assertEquals(listOf("Россия", "Hysteria 2 hy.example.com"), parsed.profiles.map { it.name })
        assertEquals(listOf("vless", "hysteria2"), parsed.profiles.map { it.proto })
        assertEquals(listOf("ss://abc@host:8388"), parsed.rejected)
    }

    @Test
    fun `plan expands hops in chain order`() {
        val store = CepLogic.decode(macStore)
        val plan = CepLogic.plan(store.chains.single().copy(hops = listOf("B2", "A1")), store.profiles)

        assertEquals(listOf("OpenConnect 1", "VLESS 1"), plan.hops.map { it.name })
        assertTrue(plan.blockIPv6)
    }

    @Test
    fun `new profile name counts profiles of the same protocol`() {
        val profiles = listOf(CepProfile(name = "a", proto = "vless"), CepProfile(name = "b", proto = "openvpn"))

        assertEquals("VLESS 2", CepLogic.newProfileName(VpnProtocol.VLESS, profiles))
        assertEquals(listOf("1.1.1.1", "8.8.8.8"), CepLogic.parseDns("1.1.1.1, 8.8.8.8;"))
    }

    @Test
    fun `transit and useArgon fields survive json round trip and ignore legacy argonServer`() {
        val legacyJson = """
            {
              "profiles": [
                {"id":"T1","name":"Транзит","proto":"transit","server":"relay.example.com:51820"},
                {"id":"V1","name":"VLESS","proto":"vless","uri":"vless://u@h:443","useArgon":true,"argonServer":"old.example.com","argonPassword":"pw"}
              ],
              "chains": [{"id":"C1","name":"Цепочка","hops":["T1","V1"]}]
            }
        """.trimIndent()
        val decoded = CepLogic.decode(legacyJson)
        assertEquals(listOf(VpnProtocol.TRANSIT, VpnProtocol.VLESS), decoded.profiles.map { it.protocol })
        assertTrue(decoded.profiles[1].useArgon)
        assertEquals("pw", decoded.profiles[1].argonPassword)
        assertEquals(decoded, CepLogic.decode(CepLogic.encode(decoded)))
    }

    @Test
    fun `behavior profile defaults and round trip remain backward compatible`() {
        val store = CepStore(
            profiles = listOf(
                CepProfile(
                    id = "A5",
                    name = "Argon Video",
                    proto = "argon4",
                    server = "1.2.3.4:51820",
                    password = "pw",
                    argonBehaviorProfile = "video-call",
                    argonLowPowerMode = true,
                    argonVideoCallMaxDurationSec = 3600,
                ),
            ),
            chains = listOf(CepChain(id = "C5", name = "vc", hops = listOf("A5"))),
        )
        val decoded = CepLogic.decode(CepLogic.encode(store))
        assertEquals("video-call", decoded.profiles.single().argonBehaviorProfile)
        assertTrue(decoded.profiles.single().argonLowPowerMode)
        assertEquals(3600, decoded.profiles.single().argonVideoCallMaxDurationSec)
    }
}
