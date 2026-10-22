/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./admin/**/*.{php,html,js}",
    "./includes/**/*.{php,html,js}",
    "./index.php",
    "./index.html",
    "./api/**/*.{php,json}"
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef2ff',
          100: '#e0e7ff',
          500: '#6366f1',
          600: '#4f46e5',
          700: '#4338ca',
          primary: '#4f46e5'
        }
      }
    },
  },
  plugins: [],
}
