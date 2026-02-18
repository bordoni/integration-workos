const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'role-mapping': path.resolve( __dirname, 'src/js/role-mapping.js' ),
		'redirect-urls': path.resolve( __dirname, 'src/js/redirect-urls.js' ),
		'logout-redirect-urls': path.resolve( __dirname, 'src/js/logout-redirect-urls.js' ),
	},
};
