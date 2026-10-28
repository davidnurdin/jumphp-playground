<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The categories of the games directory. */
final class Version20261028230000 extends AbstractMigration
{
    private const CATEGORIES = [
        ['plateforme', 'Plates-formes', 'Sauter, grimper, éviter : le genre roi des jeux rétro.'],
        ['action', 'Action', 'Réflexes, combats et explosions.'],
        ['arcade', 'Arcade', 'Parties courtes, scores qui montent : un crédit de plus !'],
        ['tir', 'Tir', "Shoot'em up, vaisseaux et pluies de projectiles."],
        ['reflexion', 'Réflexion', 'Puzzles, casse-têtes et logique.'],
        ['aventure', 'Aventure', 'Explorer, trouver, raconter.'],
        ['jeu-de-role', 'Jeu de rôle', "Héros, quêtes et points d'expérience."],
        ['course', 'Course', 'Vitesse, circuits et dérapages.'],
        ['sport', 'Sport', 'Balles, buts et records.'],
        ['strategie', 'Stratégie', "Réfléchir avant d'agir, au tour par tour ou en temps réel."],
        ['multijoueur', 'Multijoueur', 'À plusieurs, sur le même écran ou en ligne.'],
        ['3d', '3D', 'Des jeux en 3D avec OpenGL / WebGL.'],
        ['remake', 'Remakes', 'Les classiques des micros 8 bits, refaits en PHP.'],
        ['demo', 'Démos et expériences', 'Effets, prototypes et petits jouets.'],
    ];

    public function getDescription(): string
    {
        return 'The categories of the games directory';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CATEGORIES as $i => [$slug, $name, $description]) {
            $this->addSql('INSERT INTO category (slug, name, description, position) VALUES (?, ?, ?, ?)', [$slug, $name, $description, $i * 10]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM category');
    }
}
