/** @type {import('next').NextConfig} */
const path = require("path");

const nextConfig = {
  reactStrictMode: true,
  transpilePackages: ["@scholaris/shared", "@scholaris/ui"],
  // Fix monorepo symlink resolution: prevent RSC webpack plugin from following
  // npm workspace symlinks outside the app directory (causes clientModules undefined)
  webpack: (config) => {
    config.resolve.symlinks = false;
    return config;
  },
};

module.exports = nextConfig;
