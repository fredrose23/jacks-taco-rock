<?php
/**
 * Endpoints de autenticación
 */
return [
    'me' => function () {
        json_response(['user' => current_user()]);
    },
    'logout' => function () {
        logout();
        json_response(['ok' => true]);
    },
];
