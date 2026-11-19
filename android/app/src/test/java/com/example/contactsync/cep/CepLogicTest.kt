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

    @Test
    fun `bypass parses hosts and networks and rejects bad masks`() {
        assertEquals(listOf("192.168.0.0/16", "203.0.113.7/32"), CepLogic.parseCidrs("192.168.0.0/16, 203.0.113.7").getOrThrow())
        assertTrue(CepLogic.parseCidrs("10.0.0.0/33").isFailure)
        assertTrue(CepLogic.parseCidrs("example.com").isFailure)
        assertEquals(emptyList<String>(), CepLogic.parseCidrs("  ").getOrThrow())
    }

    @Test
    fun `chain settings reach the plan and default on for old files`() {
        val store = CepLogic.decode(macStore)
        val chain = store.chains.single()
        assertTrue(chain.autoReconnect)
        assertTrue(chain.killSwitch)
        assertEquals("", chain.bypass)
        val plan = CepLogic.plan(chain.copy(bypass = "10.0.0.0/8", killSwitch = false), store.profiles)
        assertEquals("10.0.0.0/8", plan.bypass)
        assertEquals(false, plan.killSwitch)
    }

    @Test
    fun `reconnect delay grows and caps at a minute`() {
        assertEquals(listOf(2000L, 5000L, 10000L, 20000L, 30000L, 60000L, 60000L), (1..7).map(CepLogic::reconnectDelayMs))
    }

    @Test
    fun `health targets skip local and bypassed servers`() {
        assertEquals(listOf("9.9.9.9"), CepLogic.healthTargets(listOf("192.168.1.1", "9.9.9.9", "1.1.1.1"), listOf("1.1.1.1/32")))
        assertEquals(listOf("1.1.1.1", "8.8.8.8"), CepLogic.healthTargets(emptyList(), emptyList()))
        assertTrue(CepLogic.cidrContains("172.16.0.0/12", "172.31.255.1"))
        assertTrue(!CepLogic.cidrContains("172.16.0.0/12", "172.32.0.1"))
    }

    @Test
    fun `dns probe answer must match id and not be servfail`() {
        val id = 0x1234
        val ok = CepLogic.dnsProbeQuery(id).copyOf(12).also { it[2] = 0x81.toByte(); it[3] = 0x80.toByte() }
        assertTrue(CepLogic.isDnsProbeAnswer(id, ok, 12))
        val servfail = ok.copyOf().also { it[3] = 0x82.toByte() }
        assertTrue(!CepLogic.isDnsProbeAnswer(id, servfail, 12))
        assertTrue(!CepLogic.isDnsProbeAnswer(0x4321, ok, 12))
    }

    private val subLinks = listOf(
        "vless://11111111-2222-3333-4444-555555555555@a.example.com:443?type=xhttp&security=tls#%F0%9F%87%A9%F0%9F%87%AA%20Frankfurt%20",
        "vless://11111111-2222-3333-4444-555555555555@b.example.com:443?type=grpc&security=tls#USA",
        "trojan://secret@c.example.com:443#Trojan",
        "hy2://pw@d.example.com:8443",
        "vless://11111111-2222-3333-4444-555555555555@e.example.com:443?type=tcp#USA",
    ).joinToString("\n")

    @Test
    fun `subscription parses base64 url-safe and plain text the same way as macOS`() {
        val b64 = java.util.Base64.getEncoder().encodeToString(subLinks.toByteArray())
        val urlSafe = b64.replace('+', '-').replace('/', '_').trimEnd('=')
        for (body in listOf(b64, urlSafe, subLinks)) {
            assertEquals(
                listOf("🇩🇪 Frankfurt", "USA", "Trojan", "d.example.com:8443", "USA (2)"),
                CepLogic.parseSubscription(body).map { it.name },
            )
        }
    }

    @Test
    fun `subscription servers report what android can run`() {
        val servers = CepLogic.parseSubscription(subLinks)
        assertEquals(listOf(VpnProtocol.VLESS, VpnProtocol.VLESS, null, VpnProtocol.HYSTERIA2, VpnProtocol.VLESS), servers.map { it.protocol })
        assertEquals(null, CepLogic.serverUnsupportedReason(servers[0]))
        assertEquals(null, CepLogic.serverUnsupportedReason(servers[1]))
        assertTrue(CepLogic.serverUnsupportedReason(servers[2]) != null)
        assertTrue(CepLogic.isStub(CepLogic.parseSubscription("vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1#stub")))
        assertTrue(!CepLogic.isStub(servers))
    }

    @Test
    fun `refresh updates linked profiles by name then by old address`() {
        val servers = CepLogic.parseSubscription(subLinks)
        val sub = CepSubscription(id = "S", name = "sub.example.com", url = "https://sub.example.com/x")
        val byName = CepProfile(id = "P1", name = "USA", proto = "vless", uri = "vless://old@b.example.com:443#USA", subscriptionID = "S", subscriptionServer = "USA")
        val renamed = CepProfile(id = "P2", name = "Мой", proto = "vless", uri = "vless://old@a.example.com:443#Germany", subscriptionID = "S", subscriptionServer = "Germany")
        val other = CepProfile(id = "P3", name = "x", proto = "vless", uri = "vless://keep@z.example.com:1")
        val store = CepStore(profiles = listOf(byName, renamed, other), subscriptions = listOf(sub))
        val next = CepLogic.applySubscription(store, "S", servers, "Провайдер", "использовано", 1_000_000_000.0)
        assertEquals(servers[1].uri, next.profiles[0].uri)
        assertEquals(servers[0].uri, next.profiles[1].uri)
        assertEquals("🇩🇪 Frankfurt", next.profiles[1].subscriptionServer)
        assertEquals("Мой", next.profiles[1].name)
        assertEquals(other, next.profiles[2])
        assertEquals("Провайдер", next.subscriptions[0].name)
        assertEquals(1_000_000_000.0 - CepLogic.APPLE_EPOCH, next.subscriptions[0].updatedAt!!, 0.001)
    }

    @Test
    fun `subscription headers and hwid match macOS`() {
        assertEquals("Моя подписка", CepLogic.subscriptionTitle("base64:" + java.util.Base64.getEncoder().encodeToString("Моя подписка".toByteArray())))
        assertEquals("использовано 1.0 ГБ из ∞", CepLogic.subscriptionUserInfo("upload=0; download=1073741824; total=0; expire=0"))
        // то же значение считает SubscriptionParser.hwid на macOS для seed "ABC"
        assertEquals("4fc80a53394536936f76a8e55471e683", CepLogic.hwid("ABC"))
        assertEquals(CepLogic.hwid("ABC"), CepLogic.hwid("ABC"))
    }

    @Test
    fun `mac store with subscriptions decodes`() {
        val json = """{"chains":[],"profiles":[{"id":"P","name":"n","proto":"vless","uri":"vless://u@h:1","subscriptionID":"S","subscriptionServer":"USA"}],
            "subscriptions":[{"id":"S","name":"Sub","url":"https://s","hwid":"","servers":[{"name":"USA","uri":"vless://u@h:1#USA"}],"updatedAt":785000000.5}]}"""
        val store = CepLogic.decode(json)
        assertEquals("S", store.profiles.single().subscriptionID)
        assertEquals("USA", store.subscriptions.single().servers.single().name)
    }
}
