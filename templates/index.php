<?php
/**
 * Main template for MovieDB app
 * Loads the Vue.js application
 */

/** @var array $_ */
$appPath = $_['faviconPath'];
$nonce = \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce();
?>
<div id="moviedb"></div>
<script nonce="<?php p($nonce); ?>">
	(function() {
		var link = document.querySelector("link[rel*='icon']") || document.createElement('link');
		link.type = 'image/svg+xml';
		link.rel = 'icon';
		link.href = '<?php p($appPath); ?>';
		document.head.appendChild(link);
	})();
</script>
