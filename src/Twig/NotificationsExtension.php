<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\CommentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** notifications_count(): the number on the bell of the header (CommentRepository::countNotifications) */
final class NotificationsExtension extends AbstractExtension
{
    private ?int $count = null;

    public function __construct(private Security $security, private CommentRepository $comments)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('notifications_count', $this->count(...))];
    }

    public function count(): int
    {
        $user = $this->security->getUser();
        return $this->count ??= $user instanceof User ? $this->comments->countNotifications($user) : 0;
    }
}
