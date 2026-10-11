<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Проверка доступа в интернет для Android. Система после подключения к Wi-Fi запрашивает этот адрес
 * и ждёт пустой ответ 204: другой ответ — «вход в сеть» (портал), нет ответа — «без доступа в интернет».
 * Стандартные адреса Google из нашей сети отвечают не всегда, поэтому планшет проверяет связь через наш сервер.
 */
final class ConnectivityController
{
    #[Route('/generate_204', name: 'connectivity_check', methods: ['GET', 'HEAD'])]
    public function check(): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}
