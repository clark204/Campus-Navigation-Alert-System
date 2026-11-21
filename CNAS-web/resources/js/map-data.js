// The campus map from GET /api/map (the map tables in the database), shared by every script on a page that draws or
// searches it: { buildings, paths, entrances, stairs, landmarks }. The first four are GeoJSON FeatureCollections
// ("entrances" are the rooms); landmarks holds the friendly names used in walking directions.

const requests = new Map();

// One request per URL per page. A failed request is forgotten so a later call can try again.
export function loadMapData(url, fetchImpl = fetch) {
    if (!requests.has(url)) {
        const request = fetchImpl(url, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error(`Map data: HTTP ${response.status}`);
                return response.json();
            })
            .then((body) => {
                if (!body?.data) throw new Error('Map data: the reply had no map in it');
                return body.data;
            });
        request.catch(() => requests.delete(url));
        requests.set(url, request);
    }
    return requests.get(url);
}
