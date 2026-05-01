const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin': path.resolve( __dirname, 'src/js/admin.js' ),
		'role-mapping': path.resolve( __dirname, 'src/js/role-mapping.js' ),
		'redirect-urls': path.resolve( __dirname, 'src/js/redirect-urls.js' ),
		'logout-redirect-urls': path.resolve( __dirname, 'src/js/logout-redirect-urls.js' ),
		'organization-refresh': path.resolve( __dirname, 'src/js/organization-refresh.js' ),
		'login-button': path.resolve( __dirname, 'src/js/login-button/index.js' ),
		'login-button-frontend': path.resolve( __dirname, 'src/js/login-button-frontend.js' ),
		'onboarding': path.resolve( __dirname, 'src/js/onboarding.js' ),
		'authkit': path.resolve( __dirname, 'src/js/authkit/index.tsx' ),
		'admin-profiles': path.resolve( __dirname, 'src/js/admin-profiles/index.tsx' ),
	},
};
