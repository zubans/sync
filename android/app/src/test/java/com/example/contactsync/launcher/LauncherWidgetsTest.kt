package com.example.contactsync.launcher

import org.junit.Assert.assertEquals
import org.junit.Test

class LauncherWidgetsTest {
    @Test
    fun `yandex suggestions are taken from second element`() {
        val body = """["погода москва",["погода москва","погода москва на неделю","погода москва"]]"""
        assertEquals(listOf("погода москва", "погода москва на неделю"), YandexSearch.parseSuggestions(body))
    }

    @Test
    fun `yandex search url encodes the query`() {
        assertEquals("https://yandex.ru/search/?text=%D0%BA%D1%83%D1%80%D1%81%20%D1%8E%D0%B0%D0%BD%D1%8F", YandexSearch.searchUrl(" курс юаня "))
    }

    @Test
    fun `vpn lamp states`() {
        assertEquals(VpnState.UP, VpnLamp.state(VpnProbe(true, "192.168.100.109", kbReachable = true)))
        assertEquals(VpnState.DEGRADED, VpnLamp.state(VpnProbe(true, "192.168.100.109", kbReachable = false)))
        assertEquals(VpnState.DOWN, VpnLamp.state(VpnProbe(true, null, kbReachable = false)))
        assertEquals(VpnState.DOWN, VpnLamp.state(VpnProbe(false, null, kbReachable = false)))
    }

    @Test
    fun `boot command skips missing script and logs output`() {
        val command = BootScript.command("/data/local/boot.sh", "/data/local/boot.log")
        assertEquals(
            "[ -f /data/local/boot.sh ] || exit 0; echo \"== \$(date) boot\" >> /data/local/boot.log; sh /data/local/boot.sh >> /data/local/boot.log 2>&1",
            command,
        )
    }
}
