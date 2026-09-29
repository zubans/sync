<?php

namespace App\Controller\Admin;

use App\Service\ContactPhotoStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Фото контактов для админки (доступ — по access_control для /admin, только ROLE_ADMIN). */
final class ContactPhotoController extends AbstractController
{
    #[Route('/admin/contact-photo/{sha256}', name: 'admin_contact_photo', requirements: ['sha256' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function __invoke(string $sha256, ContactPhotoStorage $photos): BinaryFileResponse
    {
        if (!$photos->has($sha256)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($photos->path($sha256));
        $response->headers->set('Content-Type', $photos->mimeType($sha256));

        return $response->setPrivate();
    }
}
