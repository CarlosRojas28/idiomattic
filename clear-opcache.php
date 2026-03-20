<?php
/**
 * Temporal — borrar este archivo después de usarlo una vez.
 * Acceder via: http://idiomattic.test/wp-content/plugins/idiomattic-wp/clear-opcache.php
 */
if ( function_exists( 'opcache_reset' ) ) {
    opcache_reset();
    echo 'OPcache cleared OK — borra este archivo ahora.';
} else {
    echo 'OPcache no está activo — el archivo debería haberse cargado fresco.';
}
