<?php

// Minimal pt_BR messages for the rules the API uses. Unlisted rules fall back to APP_FALLBACK_LOCALE (en).
return [
    'array' => 'O campo :attribute é inválido.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'email' => 'Informe um e-mail válido.',
    'in' => 'O valor de :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'numeric' => 'O campo :attribute deve ser no máximo :max.',
        'string' => 'O campo :attribute deve ter no máximo :max caracteres.',
    ],
    'min' => [
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'password' => [
        'letters' => 'A senha deve conter letras.',
        'mixed' => 'A senha deve conter letras maiúsculas e minúsculas.',
        'numbers' => 'A senha deve conter números.',
        'symbols' => 'A senha deve conter símbolos.',
        'uncompromised' => 'Esta senha apareceu em um vazamento de dados. Escolha outra.',
    ],
    'required' => 'Preencha o campo :attribute.',
    'string' => 'O campo :attribute deve ser um texto.',
    'timezone' => 'Informe um fuso horário IANA válido, por exemplo America/Sao_Paulo.',
    'unique' => 'Este :attribute já está em uso.',

    'attributes' => [
        'email' => 'e-mail',
        'password' => 'senha',
        'name' => 'nome',
    ],
];
