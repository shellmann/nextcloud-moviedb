const path = require('path')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const packageJson = require('./package.json')

// Disable code splitting to avoid chunk loading issues
webpackConfig.optimization = {
    ...webpackConfig.optimization,
    splitChunks: false,
}

// Inject app version from package.json at build time
webpackConfig.plugins.push(
    new webpack.DefinePlugin({
        __APP_VERSION__: JSON.stringify(packageJson.version),
    }),
)

module.exports = webpackConfig
