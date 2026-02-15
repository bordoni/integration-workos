const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'role-mapping': path.resolve( __dirname, 'src/js/role-mapping.js' ),
	},
};
