<?php

use Illuminate\Support\Facades\Route;

// Sirve la SPA de React (resources/frontend) para cualquier ruta que no sea
// /api, /storage o /build — el enrutamiento real de página a página lo hace
// react-router-dom en el navegador, esta vista es el único punto de entrada.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|storage|build).*$');
