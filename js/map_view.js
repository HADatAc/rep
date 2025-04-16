(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.repMapLibre = {
    attach: function (context, settings) {
      // Avoid re-initializing the map if it's already loaded
      if (!document.getElementById('mapid') || $('#mapid').hasClass('map-loaded')) {
        return;
      }
      $('#mapid').addClass('map-loaded');

      // Convert PHP markers into GeoJSON features
      const features = (drupalSettings.repMap.markers || []).map(item => ({
        type: 'Feature',
        geometry: {
          type: 'Point',
          coordinates: [item.lng, item.lat],
        },
        properties: {
          title: item.label
        }
      }));

      // Initialize the MapLibre map
      const map = new maplibregl.Map({
        container: 'mapid',
        style: 'https://api.maptiler.com/maps/basic-v2/style.json?key=6VOSZjObhdGrBIQL9n1F',
        center: [-8.5, 39.5],
        zoom: 5
      });

      // Add zoom and rotation controls to the map
      map.addControl(new maplibregl.NavigationControl());

      map.on('style.load', function () {
        // Load the PNG icon as a marker (SVG is not supported)
        const markerUrl = drupalSettings.repMap.base_url + '/modules/custom/rep/images/icons/building-solid.png';

        map.loadImage(markerUrl, function (error, image) {
          if (error) throw error;

          // Register the marker image with the map
          if (!map.hasImage('default-marker')) {
            map.addImage('default-marker', image);
          }

          // Add GeoJSON source with clustering enabled
          map.addSource('points', {
            type: 'geojson',
            data: {
              type: 'FeatureCollection',
              features: features
            },
            cluster: true,
            clusterMaxZoom: 14,
            clusterRadius: 50
          });

          // Cluster layer with translucent blue circles
          map.addLayer({
            id: 'clusters',
            type: 'circle',
            source: 'points',
            filter: ['has', 'point_count'],
            paint: {
              'circle-color': 'rgba(0,122,255,0.6)', // blue with transparency
              'circle-radius': [
                'step', ['get', 'point_count'],
                15, 10, 20, 30, 25
              ],
              'circle-stroke-width': 2,
              'circle-stroke-color': '#ffffff'
            }
          });

          // Text label inside each cluster
          map.addLayer({
            id: 'cluster-count',
            type: 'symbol',
            source: 'points',
            filter: ['has', 'point_count'],
            layout: {
              'text-field': '{point_count_abbreviated}',
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-size': 12
            },
            paint: {
              'text-color': '#ffffff'
            }
          });

          // Marker + label for unclustered points
          map.addLayer({
            id: 'unclustered-point',
            type: 'symbol',
            source: 'points',
            filter: ['!', ['has', 'point_count']],
            layout: {
              'icon-image': 'default-marker',
              'icon-size': 0.05, // adjust depending on PNG resolution
              'icon-anchor': 'bottom',
              'text-field': ['get', 'title'],
              'text-offset': [0, 1.2],
              'text-anchor': 'top',
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-size': 13
            }
          });

          // Show popup on individual marker click
          map.on('click', 'unclustered-point', function (e) {
            const coordinates = e.features[0].geometry.coordinates.slice();
            const title = e.features[0].properties.title;
            new maplibregl.Popup()
              .setLngLat(coordinates)
              .setHTML('<strong>' + title + '</strong>')
              .addTo(map);
          });

          // Zoom into cluster when clicked
          map.on('click', 'clusters', function (e) {
            const features = map.queryRenderedFeatures(e.point, { layers: ['clusters'] });
            const clusterId = features[0].properties.cluster_id;
            map.getSource('points').getClusterExpansionZoom(clusterId, function (err, zoom) {
              if (err) return;
              map.easeTo({
                center: features[0].geometry.coordinates,
                zoom: zoom
              });
            });
          });

          // Change cursor style on cluster hover
          map.on('mouseenter', 'clusters', () => map.getCanvas().style.cursor = 'pointer');
          map.on('mouseleave', 'clusters', () => map.getCanvas().style.cursor = '');
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
