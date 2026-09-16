<?php

require_once __DIR__ . '../Common/Constants/Default.Constants.php';

use Common\Constants\DefaultConstants;

function studentValidate($data)
{
    foreach (DefaultConstants::DEFAULT_FIELDS as $campo) {
        if (empty($data[$campo])) {
            return "Campo obrigatório ausente: $campo";
        }
    }

    if (!in_array($data['sexo'], DefaultConstants::SEX)) {
        return "Campo sexo inválido. Use M ou F.";
    }

    if (!in_array($data['curso'], DefaultConstants::DEFAULT_COURSES)) {
        return "Curso inválido. Use CFS ou EAGS.";
    }

    if ($data['curso'] === DefaultConstants::DEFAULT_COURSES[1] && $data['serie'] !== DefaultConstants::DEFAULT_COURSES[1]) {
        return "Para o curso EAGS, a série deve ser 'EAGS'.";
    }

    if ($data['curso'] === DefaultConstants::DEFAULT_COURSES[0] && !in_array($data['serie'], DefaultConstants::DEFAULT_SERIES_CFS)) {
        return "Para o curso CFS, a série deve ser 1, 2, 3 ou 4.";
    }

    if (!empty($data['qrcode_hash']) && !preg_match('/^[a-f0-9]{64}$/i', $data['qrcode_hash'])) {
        return "qrcode_hash inválido: deve ser um hash sha256 (64 caracteres hexadecimais).";
    }

    return null;
}
