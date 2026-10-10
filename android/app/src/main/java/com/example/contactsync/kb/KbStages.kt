package com.example.contactsync.kb

/** Как подсветить стадию: цвет чипа в виджете и на экране задач. */
enum class StageTone { ACTIVE, REVIEW, WAITING, DONE, FAILED }

data class TaskStage(val label: String, val tone: StageTone)

/**
 * Стадия задачи для виджета. Если по задаче есть прогон модели, важнее он (что модель делает сейчас),
 * иначе — статус самой задачи. Модель «работает», пока прогон в planning / discussing / running
 * или идёт авторевью — так же считает веб-интерфейс KB (ACTIVE_RUN_STATUSES).
 */
object KbStages {
    private val ACTIVE_RUN = setOf("planning", "discussing", "running")

    fun stage(task: KbTask): TaskStage {
        val run = task.run
        if (run != null && task.status !in setOf("done", "cancelled")) {
            if (run.reviewStatus == "running") return TaskStage("авторевью", StageTone.ACTIVE)
            when (run.status) {
                "planning" -> return TaskStage("пишет план", StageTone.ACTIVE)
                "planned" -> return TaskStage("план готов", StageTone.WAITING)
                "discussing" -> return TaskStage("обсуждение", StageTone.ACTIVE)
                "running" -> return TaskStage("правки", StageTone.ACTIVE)
                "review" -> return TaskStage(
                    if (run.openComments > 0) "ревью · замечаний ${run.openComments}" else "ревью diff",
                    StageTone.REVIEW,
                )
                "failed" -> return TaskStage("ошибка прогона", StageTone.FAILED)
            }
        }
        return when (task.status) {
            "in_progress" -> TaskStage("в работе", StageTone.ACTIVE)
            "review" -> TaskStage("на ревью", StageTone.REVIEW)
            "todo" -> TaskStage("к выполнению", StageTone.WAITING)
            "tech_debt" -> TaskStage("техдолг", StageTone.WAITING)
            "done" -> TaskStage("готово", StageTone.DONE)
            "cancelled" -> TaskStage("отменена", StageTone.DONE)
            else -> TaskStage(task.status, StageTone.WAITING)
        }
    }

    /** Модель сейчас что-то делает по задаче — опрашивать KB чаще. */
    fun isRunning(task: KbTask): Boolean =
        task.run != null && (task.run.status in ACTIVE_RUN || task.run.reviewStatus == "running")

    /** Порядок в виджете: что идёт сейчас, потом ждущее ревью, потом остальное (порядок KB внутри групп). */
    fun forWidget(tasks: List<KbTask>): List<KbTask> =
        tasks.filter { it.type != "epic" }.sortedBy {
            when (stage(it).tone) {
                StageTone.ACTIVE -> 0
                StageTone.REVIEW -> 1
                StageTone.FAILED -> 2
                StageTone.WAITING -> 3
                StageTone.DONE -> 4
            }
        }

    /** Страница задачи в веб-интерфейсе KB; эпики открываются своей страницей. */
    fun taskUrl(baseUrl: String, task: KbTask): String =
        "${baseUrl.trimEnd('/')}/projects/${task.projectSlug}/${if (task.type == "epic") "epics" else "tasks"}/${task.id}"

    /** Подпись обработки встречи на сервере; null — обработка закончена, показывать нечего. */
    fun meetingStatus(meeting: KbMeeting): String? {
        val percent = meeting.progress?.takeIf { it.total > 0 }?.let { " ${it.done * 100 / it.total}%" }.orEmpty()
        return when (meeting.status) {
            "uploaded", "compressing" -> "сжатие"
            "ready" -> "ждёт расшифровки"
            "transcribing" -> "расшифровка$percent"
            "postprocessing" -> "обработка текста$percent"
            "waiting_local" -> "в очереди на расшифровку"
            "needs_choice" -> "выберите модель в KB"
            "failed" -> "ошибка: ${meeting.error ?: "см. KB"}"
            else -> null
        }
    }
}
