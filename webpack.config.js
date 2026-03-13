const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Disable code splitting to avoid chunk loading issues
webpackConfig.optimization = {
    ...webpackConfig.optimization,
    splitChunks: false,
}

module.exports = webpackConfig
