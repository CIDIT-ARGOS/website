<?php

namespace Common\Constants;

class ControlPainelConstants
{
    public const CARDS = [
        [
            'href' => "api.php",
            'icon' => "braces",
            'title' => "API",
            'description' => "Endpoints, chaves de acesso e monitoramento das requisições.",
            'footer' => "Gerenciar API"
        ],
        [
            'href' => "banco.php",
            'icon' => "database",
            'title' => "Banco de Dados",
            'description' => "Gerenciamento e configuração do banco de dados.",
            'footer' => "Gerenciar Banco"
        ],
        [
            'href' => "usuarios.php",
            'icon' => "users",
            'title' => "Usuários",
            'description' => "Gerenciamento de usuários e permissões.",
            'footer' => "Gerenciar Usuários"
        ],
        [
            'href' => "backup.php",
            'icon' => "database-backup",
            'title' => "Backup",
            'description' => "Gerenciamento e configuração de backups do sistema.",
            'footer' => "Gerenciar Backup"
        ],
        [
            'href' => "../painel/",
            'icon' => "layout-dashboard",
            'title' => "Painel de Comando",
            'description' => "Acesse efetivo, retiradas, relatórios e demais funções operacionais.",
            'footer' => "Acessar painel"
        ]
    ];
}
