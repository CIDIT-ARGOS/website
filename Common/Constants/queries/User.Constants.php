<?php

namespace Common\Constants\queries;

class UserConstants
{
    public const UPDATE_LAST_LOGIN = "UPDATE admin_usuarios SET ultimo_login = NOW() WHERE id = ?";
    public const FIND_BY_USER = "SELECT id, nome, usuario, senha_hash, nivel, ativo FROM admin_usuarios WHERE usuario = ? LIMIT 1";
}
