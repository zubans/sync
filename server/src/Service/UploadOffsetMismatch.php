<?php

namespace App\Service;

/** Клиент продолжает загрузку не с того места: ему нужно начать с expected. */
final class UploadOffsetMismatch extends \RuntimeException
{
    public function __construct(public readonly int $expected)
    {
        parent::__construct("Ожидалось смещение $expected.");
    }
}
