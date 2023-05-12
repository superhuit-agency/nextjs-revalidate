const path = require('path');

const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

const DEV = process.env.NODE_ENV === 'development';

module.exports = {
	entry: {
		'admin': './src/index.ts',
	},
	module: {
		rules: [
			{
				test: /\.tsx?$/,
				use: 'ts-loader',
				exclude: /node_modules/,
			},
		],
	},
	resolve: {
		extensions: ['.tsx', '.ts', '.js'],
	},
	output: {
		publicPath: '',
		path: path.resolve(__dirname, 'dist'),
		filename: DEV ? '[name].js' : '[name].[fullhash].js',
	},
	plugins: [
		new CleanWebpackPlugin(),
		new WebpackManifestPlugin(),
	],
	devServer: {
		host: 'localhost',
		port: 8000,
		allowedHosts: "all",
		static: { directory: path.join(__dirname, './dist'), },
	},

};
