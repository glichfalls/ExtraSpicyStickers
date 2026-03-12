<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260311210848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sticker_pack (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, sticker_count INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_30DA8C815E237E06 (name), INDEX IDX_30DA8C81A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, telegram_id BIGINT NOT NULL, username VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) NOT NULL, sticker_pack_name VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649CC0B3066 (telegram_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sticker_pack ADD CONSTRAINT FK_30DA8C81A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sticker_pack DROP FOREIGN KEY FK_30DA8C81A76ED395');
        $this->addSql('DROP TABLE sticker_pack');
        $this->addSql('DROP TABLE `user`');
    }
}
