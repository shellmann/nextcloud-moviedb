<?php
/**
 * Main template for MovieDB app
 * Loads the Vue.js application
 */

// Set the app favicon
$appPath = \OC::$server->getURLGenerator()->imagePath('moviedb', 'favicon.svg');
?>
<div id="moviedb"></div>
<script nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>">
	(function() {
		var link = document.querySelector("link[rel*='icon']") || document.createElement('link');
		link.type = 'image/svg+xml';
		link.rel = 'icon';
		link.href = '<?php p($appPath); ?>';
		document.head.appendChild(link);
	})();
</script>
