// Adds the Firebase file (google-services.json) for Android push to the config in app.json, without committing it:
// - EAS builds: the file comes from the EAS environment variable GOOGLE_SERVICES_JSON (type "file"), see README.md.
// - Local builds (npx expo run:android, eas build --local): ./google-services.json when it is there.
// Expo Go doesn't need it. Everything else stays in app.json.
const fs = require('fs');
const path = require('path');

module.exports = ({ config }) => {
  const local = path.join(__dirname, 'google-services.json');
  const googleServicesFile = process.env.GOOGLE_SERVICES_JSON ?? (fs.existsSync(local) ? './google-services.json' : undefined);

  return {
    ...config,
    android: {
      ...config.android,
      ...(googleServicesFile ? { googleServicesFile } : {}),
    },
  };
};
