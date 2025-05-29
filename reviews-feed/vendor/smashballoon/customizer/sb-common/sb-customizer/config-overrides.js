const path = require("path");
const { useBabelRc } = require("customize-cra");

module.exports = {
  webpack: (config, env) => {
    config.output.path = path.resolve("build");
    config.output.filename = "static/js/[name].js";

    // Add resolve configuration for WordPress packages
    config.resolve = {
      ...config.resolve,
      alias: {
        ...config.resolve.alias,
        'memize': path.resolve(__dirname, 'node_modules/memize'),
        '@wordpress/i18n': path.resolve(__dirname, 'node_modules/@wordpress/i18n')
      }
    };

    config.module.rules = config.module.rules.map((rule) => {
      if (rule.oneOf instanceof Array) {
        return {
          ...rule,
          oneOf: [
            {
              test: /\.(jpe?g|png|gif)$/i,
              use: [
                {
                  options: {
                    limit: 2048,
                    name: "static/images/[name].[ext]?[hash]",
                  },
                  loader: "url-loader",
                },
              ],
            },
            ...rule.oneOf,
          ],
        };
      }
      return rule;
    });

    // Update externals configuration
    config.externals = {
      jquery: "jQuery",
      '@wordpress/i18n': 'wp.i18n',
      'memize': 'memize'
    };

    // use .babelrc
    config = useBabelRc()(config);

    return config;
  },
  devServer: (configFunction) => (proxy, allowedHost) => {
    const devServerConfig = configFunction(proxy, allowedHost);

    devServerConfig.allowedHosts = ["all"];
    devServerConfig.port = 3000;
    devServerConfig.host = "localhost";
    devServerConfig.static = "/static";
    return devServerConfig;
  }
};