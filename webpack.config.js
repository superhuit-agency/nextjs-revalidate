const path = require("path");

const { CleanWebpackPlugin } = require("clean-webpack-plugin");
const { WebpackManifestPlugin } = require("webpack-manifest-plugin");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");

const DEV = process.env.NODE_ENV === "development";

module.exports = {
	entry: {
		admin: "./src/index.ts",
		settings: "./src/settings.ts",
	},
	module: {
		rules: [
			{
				test: /\.tsx?$/,
				use: "ts-loader",
				exclude: /node_modules/,
			},
			{
				test: /\.css$/,
				use: [MiniCssExtractPlugin.loader, "css-loader"],
			},
		],
	},
	resolve: {
		extensions: [".tsx", ".ts", ".js"],
	},
	output: {
		publicPath: "",
		path: path.resolve(__dirname, "dist"),
		filename: DEV ? "[name].js" : "[name].[fullhash].js",
	},
	plugins: [
		new CleanWebpackPlugin(),
		new WebpackManifestPlugin(),
		new MiniCssExtractPlugin({
			filename: DEV ? "[name].css" : "[name].[fullhash].css",
		}),
	],
	devServer: {
		host: "localhost",
		port: 8000,
		allowedHosts: "all",
		static: { directory: path.join(__dirname, "./dist") },
	},
};
