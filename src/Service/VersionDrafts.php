<?php

namespace App\Service;

use App\Entity\Project;
use App\Repository\DraftRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An old version of a game made the drafts of someone (the sandbox or the online editor): the files that differ
 * from the published version, so that they edit it, push it back (the owner) or fork it. Drafts are text: a
 * picture or a sound that changed since cannot come back this way (counted, and said).
 */
final class VersionDrafts
{
    private const MAX_FILES = 400;

    public function __construct(private GameFiles $files, private DraftRepository $drafts, private EntityManagerInterface $em)
    {
    }

    /** @return array{files: int, skipped: int} drafts made, binary files left as they are now */
    public function make(Project $project, string $sha, DraftAuthor $who): array
    {
        $old = $this->files->dir($project, $sha) ?? throw new ProjectException('Version inconnue.');
        $now = (string)$this->files->dir($project);
        $this->drafts->clear($who, $project);
        $made = $skipped = 0;
        $then = $this->files->tree($project, $sha);
        foreach ($then as $path => $size) {
            $file = "$old/game/$path";
            $current = "$now/game/$path";
            if (is_file($current) && filesize($current) === $size && hash_file('xxh128', $current) === hash_file('xxh128', $file)) {
                continue;
            }
            $content = (string)file_get_contents($file);
            if (!GameFiles::isText($path) || $size > GameFiles::MAX_EDIT || !mb_check_encoding($content, 'UTF-8') || $made >= self::MAX_FILES) {
                $skipped++;
                continue;
            }
            $draft = $who->newDraft($project, $path);
            $draft->setContent($content);
            $this->em->persist($draft);
            $made++;
        }
        // the files added since: gone in that version
        foreach (array_diff_key($this->files->tree($project), $then) as $path => $size) {
            if ($made >= self::MAX_FILES) {
                break;
            }
            $draft = $who->newDraft($project, $path);
            $draft->markDeleted();
            $this->em->persist($draft);
            $made++;
        }
        $this->em->flush();
        return ['files' => $made, 'skipped' => $skipped];
    }
}
