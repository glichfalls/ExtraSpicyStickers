<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support multiple sticker packs per user with active pack tracking';
    }

    public function up(Schema $schema): void
    {
        // Add title column to sticker_pack
        $this->addSql("ALTER TABLE sticker_pack ADD title VARCHAR(255) NOT NULL DEFAULT ''");

        // Populate title from existing pack names
        $this->addSql("UPDATE sticker_pack sp JOIN `user` u ON sp.user_id = u.id SET sp.title = CONCAT(u.first_name, '''s AI Stickers')");

        // Change sticker_pack.user_id from unique (OneToOne) to non-unique (ManyToOne)
        $this->addSql('ALTER TABLE sticker_pack DROP INDEX UNIQ_30DA8C81A76ED395, ADD INDEX IDX_30DA8C81A76ED395 (user_id)');

        // Add active_sticker_pack_id to user table
        $this->addSql('ALTER TABLE `user` ADD active_sticker_pack_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D6494E2CE5A2 FOREIGN KEY (active_sticker_pack_id) REFERENCES sticker_pack (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D6494E2CE5A2 ON `user` (active_sticker_pack_id)');

        // Set existing pack as active for each user
        $this->addSql('UPDATE `user` u JOIN sticker_pack sp ON sp.user_id = u.id SET u.active_sticker_pack_id = sp.id');
    }

    public function down(Schema $schema): void
    {
        // Remove active_sticker_pack_id from user
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6494E2CE5A2');
        $this->addSql('DROP INDEX IDX_8D93D6494E2CE5A2 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP active_sticker_pack_id');

        // Revert sticker_pack index back to unique
        $this->addSql('ALTER TABLE sticker_pack DROP INDEX IDX_30DA8C81A76ED395, ADD UNIQUE INDEX UNIQ_30DA8C81A76ED395 (user_id)');

        // Remove title column
        $this->addSql('ALTER TABLE sticker_pack DROP title');
    }
}