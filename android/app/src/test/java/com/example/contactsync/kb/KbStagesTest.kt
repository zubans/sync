package com.example.contactsync.kb

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class KbStagesTest {
    private fun task(id: Int, status: String, run: KbRun? = null, type: String = "feature") =
        KbTask(id = id, projectSlug = "sync", projectKey = "SYNC", title = "Задача $id", status = status, type = type, run = run)

    @Test
    fun `run stage wins over task status while task is open`() {
        val stage = KbStages.stage(task(1, "in_progress", KbRun(7, "running")))
        assertEquals("правки", stage.label)
        assertEquals(StageTone.ACTIVE, stage.tone)
    }

    @Test
    fun `running auto review is shown as active`() {
        val task = task(1, "review", KbRun(7, "review", reviewStatus = "running"))
        assertEquals("авторевью", KbStages.stage(task).label)
        assertTrue(KbStages.isRunning(task))
    }

    @Test
    fun `diff on review shows open comments`() {
        assertEquals("ревью · замечаний 2", KbStages.stage(task(1, "review", KbRun(7, "review", openComments = 2))).label)
    }

    @Test
    fun `done task ignores its old run`() {
        assertEquals(StageTone.DONE, KbStages.stage(task(1, "done", KbRun(7, "accepted"))).tone)
    }

    @Test
    fun `task without run uses its status`() {
        val task = task(1, "todo")
        assertEquals("к выполнению", KbStages.stage(task).label)
        assertFalse(KbStages.isRunning(task))
    }

    @Test
    fun `widget shows running first and hides epics`() {
        val tasks = listOf(
            task(1, "todo"),
            task(2, "review", KbRun(8, "review")),
            task(3, "in_progress", KbRun(9, "planning")),
            task(4, "in_progress", type = "epic"),
        )
        assertEquals(listOf(3, 2, 1), KbStages.forWidget(tasks).map { it.id })
    }

    @Test
    fun `task and epic urls`() {
        assertEquals("http://kb:8580/projects/sync/tasks/5", KbStages.taskUrl("http://kb:8580/", task(5, "todo")))
        assertEquals("http://kb:8580/projects/sync/epics/6", KbStages.taskUrl("http://kb:8580", task(6, "todo", type = "epic")))
        assertEquals("SYNC-5", task(5, "todo").code)
    }

    @Test
    fun `meeting status shows progress and disappears when done`() {
        assertEquals("расшифровка 62%", KbStages.meetingStatus(KbMeeting(1, status = "transcribing", progress = KbProgress(62, 100))))
        assertNull(KbStages.meetingStatus(KbMeeting(1, status = "done")))
    }
}
