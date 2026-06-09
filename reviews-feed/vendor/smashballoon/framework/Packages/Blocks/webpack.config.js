const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'dist'),
	},
	entry: {
		'recommended': path.resolve(__dirname, 'src/recommended/index.js'),
		'sb-feed-blocks': path.resolve(__dirname, 'src/feed-blocks/index.js'),
		'sb-elementor-editor': path.resolve(__dirname, 'src/elementor/index.js'),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'@sb-blocks': path.resolve(__dirname, 'src/shared'),
		},
	},
};
