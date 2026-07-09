/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  transpilePackages: ["@scholaris/shared", "@scholaris/ui"],
};

module.exports = nextConfig;
