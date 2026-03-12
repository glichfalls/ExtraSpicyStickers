<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260312105218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sticker (id INT AUTO_INCREMENT NOT NULL, file_id VARCHAR(255) NOT NULL, emoji VARCHAR(32) NOT NULL, prompt LONGTEXT NOT NULL, created_at DATETIME NOT NULL, pack_id INT NOT NULL, INDEX IDX_8FEDBCFD1919B217 (pack_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sticker ADD CONSTRAINT FK_8FEDBCFD1919B217 FOREIGN KEY (pack_id) REFERENCES sticker_pack (id)');
        $this->addSql('ALTER TABLE sticker_pack DROP INDEX IDX_30DA8C81A76ED395, ADD UNIQUE INDEX UNIQ_30DA8C81A76ED395 (user_id)');
        $this->addSql('ALTER TABLE sticker_pack DROP title, DROP sticker_count');
        $this->addSql('ALTER TABLE user DROP sticker_pack_name');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sticker DROP FOREIGN KEY FK_8FEDBCFD1919B217');
        $this->addSql('DROP TABLE sticker');
        $this->addSql('ALTER TABLE sticker_pack DROP INDEX UNIQ_30DA8C81A76ED395, ADD INDEX IDX_30DA8C81A76ED395 (user_id)');
        $this->addSql('ALTER TABLE sticker_pack ADD title VARCHAR(255) NOT NULL, ADD sticker_count INT NOT NULL');
        $this->addSql('ALTER TABLE `user` ADD sticker_pack_name VARCHAR(255) DEFAULT NULL');
    }
}
