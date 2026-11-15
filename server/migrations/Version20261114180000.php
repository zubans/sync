<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114180000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Администрирование VPN-узла: режим клиент/сервер, подключения CEP, цепочки, сервисы, проброс портов и пользователи';
    }

    public function up(Schema $schema): void
    {
        $userServiceJoin = new Table('vpn_user_service');
        $userServiceJoin->addColumn('vpn_user_id', Types::INTEGER, ['notnull' => true]);
        $userServiceJoin->addColumn('vpn_server_service_id', Types::INTEGER, ['notnull' => true]);
        $userServiceJoin->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('vpn_user_id', 'vpn_server_service_id')->create()
        );
        $userServiceJoin->addIndex(['vpn_user_id'], 'IDX_CDE9315EA9FC1B73');
        $userServiceJoin->addIndex(['vpn_server_service_id'], 'IDX_CDE9315E3BEA2DF6');
        $userServiceJoin->addForeignKeyConstraint('vpn_user', ['vpn_user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_VPN_USER_SERVICE_USER');
        $userServiceJoin->addForeignKeyConstraint('vpn_server_service', ['vpn_server_service_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_VPN_USER_SERVICE_SERVICE');

        $this->createTables(
            self::table('node_settings', [
                self::id(),
                self::colWithDefault('mode', Types::STRING, 'client', 16),
                self::colWithDefault('node_name', Types::STRING, 'Локальный узел', 120),
                self::col('public_host', Types::STRING, 255, nullable: true),
                self::colWithDefault('wan_interface', Types::STRING, 'eth0', 32),
                self::colWithDefault('vpn_subnet_cidr', Types::STRING, '10.44.0.0/24', 32),
                self::col('dns_servers', Types::JSON),
                self::flag('block_ipv6', true),
                self::col('active_client_chain_id', Types::INTEGER, nullable: true),
                self::flag('auto_apply_runtime', true),
                self::col('last_runtime_applied_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::colWithDefault('last_runtime_status', Types::STRING, 'pending', 32),
                self::col('last_runtime_message', Types::TEXT, nullable: true),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('vpn_connection', [
                self::id(),
                self::col('external_id', Types::STRING, 64),
                self::col('name', Types::STRING, 120),
                self::col('proto', Types::STRING, 32),
                self::col('uri', Types::TEXT, nullable: true),
                self::col('config', Types::TEXT, nullable: true),
                self::col('server', Types::STRING, 255, nullable: true),
                self::col('username', Types::STRING, 120, nullable: true),
                self::col('password', Types::STRING, 255, nullable: true),
                self::col('client_id', Types::STRING, 120, nullable: true),
                self::col('client_secret', Types::STRING, 255, nullable: true),
                self::col('openconnect_flavor', Types::STRING, 32, nullable: true),
                self::col('server_cert', Types::TEXT, nullable: true),
                self::col('auth_group', Types::STRING, 120, nullable: true),
                self::flag('ignore_cert_errors', false),
                self::col('routes', Types::TEXT, nullable: true),
                self::flag('use_argon', false),
                self::col('argon_password', Types::STRING, 255, nullable: true),
                self::col('argon_protocol_version', Types::INTEGER, nullable: true),
                self::col('argon_padding_profile', Types::STRING, 24, nullable: true),
                self::col('argon_first_packet_profile', Types::STRING, 24, nullable: true),
                self::col('argon_path_mtu', Types::INTEGER, nullable: true),
                self::col('argon_behavior_profile', Types::STRING, 24, nullable: true),
                self::flag('argon_low_power_mode', false),
                self::col('argon_video_call_max_duration_sec', Types::INTEGER, nullable: true),
                self::col('argon_handshake_max_attempts', Types::INTEGER, nullable: true),
                self::col('argon_handshake_initial_timeout_ms', Types::INTEGER, nullable: true),
                self::col('argon_handshake_max_timeout_ms', Types::INTEGER, nullable: true),
                self::col('argon_keepalive_min_idle_sec', Types::INTEGER, nullable: true),
                self::col('argon_keepalive_max_idle_sec', Types::INTEGER, nullable: true),
                self::flag('enabled', true),
                self::col('notes', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('UNIQ_99BA70549F75D7B0', ['external_id'], unique: true),
            ]),
            self::table('vpn_chain', [
                self::id(),
                self::col('external_id', Types::STRING, 64),
                self::col('name', Types::STRING, 120),
                self::col('description', Types::TEXT, nullable: true),
                self::flag('enabled', true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('UNIQ_98915D2C9F75D7B0', ['external_id'], unique: true),
            ]),
            self::table('vpn_chain_hop', [
                self::id(),
                self::col('chain_id', Types::INTEGER),
                self::col('connection_id', Types::INTEGER),
                Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setNotNull(true)->setDefaultValue(1)->create(),
            ], [
                self::index('IDX_5E80516C966C2F62', ['chain_id']),
                self::index('IDX_5E80516CDD03F01', ['connection_id']),
            ], [
                self::fk('FK_VPN_CHAIN_HOP_CHAIN', 'chain_id', 'vpn_chain', ReferentialAction::CASCADE),
                self::fk('FK_VPN_CHAIN_HOP_CONN', 'connection_id', 'vpn_connection', ReferentialAction::CASCADE),
            ]),
            self::table('vpn_server_service', [
                self::id(),
                self::col('name', Types::STRING, 120),
                self::col('proto', Types::STRING, 32),
                self::flag('enabled', true),
                self::colWithDefault('listen_host', Types::STRING, '0.0.0.0', 64),
                Column::editor()->setUnquotedName('listen_port')->setTypeName(Types::INTEGER)->setNotNull(true)->setDefaultValue(51820)->create(),
                self::colWithDefault('transport_proto', Types::STRING, 'udp', 8),
                self::col('public_host_override', Types::STRING, 255, nullable: true),
                self::col('subnet_cidr', Types::STRING, 32, nullable: true),
                self::col('master_password', Types::STRING, 255, nullable: true),
                self::flag('transit_blind', false),
                self::col('transit_target', Types::STRING, 255, nullable: true),
                self::col('transit_allow', Types::TEXT, nullable: true),
                Column::editor()->setUnquotedName('argon_protocol_version')->setTypeName(Types::INTEGER)->setNotNull(true)->setDefaultValue(5)->create(),
                self::flag('argon_disallow_v4', false),
                self::col('argon_behavior_profile', Types::STRING, 24, nullable: true),
                self::colWithDefault('argon_padding_profile', Types::STRING, 'balanced', 24),
                Column::editor()->setUnquotedName('argon_path_mtu')->setTypeName(Types::INTEGER)->setNotNull(true)->setDefaultValue(1500)->create(),
                self::flag('argon_allow_video_call', true),
                self::col('tls_domain', Types::STRING, 255, nullable: true),
                self::col('reality_private_key', Types::STRING, 255, nullable: true),
                self::col('reality_public_key', Types::STRING, 255, nullable: true),
                self::col('reality_short_id', Types::STRING, 64, nullable: true),
                self::col('obfs_password', Types::STRING, 255, nullable: true),
                self::col('extra_options', Types::JSON, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('port_forward_rule', [
                self::id(),
                self::col('name', Types::STRING, 120),
                self::flag('enabled', true),
                self::colWithDefault('protocol', Types::STRING, 'both', 8),
                self::col('listen_interface', Types::STRING, 32, nullable: true),
                self::col('listen_port', Types::INTEGER),
                self::col('listen_port_end', Types::INTEGER, nullable: true),
                self::col('target_ip', Types::STRING, 64),
                self::col('target_port', Types::INTEGER),
                self::col('source_cidr', Types::STRING, 64, nullable: true),
                self::flag('enable_masquerade', true),
                self::col('comment', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('vpn_user', [
                self::id(),
                self::col('username', Types::STRING, 64),
                self::col('display_name', Types::STRING, 120, nullable: true),
                self::flag('enabled', true),
                self::col('client_id', Types::STRING, 120),
                self::col('client_secret', Types::STRING, 255),
                self::col('vless_uuid', Types::STRING, 64),
                self::col('tunnel_password', Types::STRING, 255),
                self::col('static_vpn_ip', Types::STRING, 45, nullable: true),
                self::flag('grant_system_access', false),
                self::col('system_email', Types::STRING, 180, nullable: true),
                self::colWithDefault('system_role', Types::STRING, 'ROLE_USER', 32),
                self::col('system_user_id', Types::INTEGER, nullable: true),
                self::col('notes', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('UNIQ_C71FA830F85E0677', ['username'], unique: true),
                self::index('UNIQ_C71FA83019EB6921', ['client_id'], unique: true),
                self::index('IDX_C71FA8304B1DAF06', ['system_user_id']),
            ], [
                self::fk('FK_VPN_USER_SYS', 'system_user_id', 'app_user', ReferentialAction::SET_NULL),
            ]),
            $userServiceJoin,
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables(
            'vpn_user_service',
            'vpn_user',
            'port_forward_rule',
            'vpn_server_service',
            'vpn_chain_hop',
            'vpn_chain',
            'vpn_connection',
            'node_settings',
        );
    }

    private static function flag(string $name, bool $default): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName(Types::BOOLEAN)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }

    private static function colWithDefault(string $name, string $type, mixed $default, int $length): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setLength($length)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }
}
