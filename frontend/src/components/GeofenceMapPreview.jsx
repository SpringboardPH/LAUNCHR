// A Google Maps embed (no API key) with a radius circle overlaid in CSS.
// The embed can't draw shapes, but q=lat,lng&z=<zoom> centers the map
// deterministically, so we compute meters-per-pixel from latitude + zoom and
// size/position a circle div over it. Approximate but good enough as a radius cue.
//
// Map is centered on (lat,lng). The circle is drawn around (circleLat,circleLng),
// defaulting to the center — so in Settings the pin and circle coincide, while at
// clock-in the map centers on the employee and the circle sits over the office.

const metersPerPixel = (lat, zoom) =>
  (156543.03392 * Math.cos((lat * Math.PI) / 180)) / Math.pow(2, zoom)

export default function GeofenceMapPreview({
  lat, lng, zoom = 15, radiusM = 0, circleLat, circleLng, height = 180,
}) {
  const cLat = circleLat ?? lat
  const cLng = circleLng ?? lng
  const mpp = metersPerPixel(lat, zoom)

  // Offset of the circle centre from the map centre, in pixels.
  const metersNorth = (Number(cLat) - lat) * 110574
  const metersEast = (Number(cLng) - lng) * 111320 * Math.cos((lat * Math.PI) / 180)
  const dxPx = metersEast / mpp
  const dyPx = metersNorth / mpp
  const diameter = radiusM > 0 ? (radiusM / mpp) * 2 : 0

  return (
    <div className="relative rounded-lg overflow-hidden border border-gray-200">
      <iframe
        title="Location map"
        width="100%"
        height={height}
        loading="lazy"
        referrerPolicy="no-referrer-when-downgrade"
        className="block"
        src={`https://www.google.com/maps?q=${lat},${lng}&z=${zoom}&output=embed`}
      />
      {radiusM > 0 && Number.isFinite(diameter) && (
        <div
          className="pointer-events-none absolute rounded-full"
          style={{
            left: `calc(50% + ${dxPx}px)`,
            top: `calc(50% - ${dyPx}px)`,
            width: `${diameter}px`,
            height: `${diameter}px`,
            transform: 'translate(-50%, -50%)',
            background: 'rgba(37, 99, 235, 0.15)',
            border: '2px solid rgba(37, 99, 235, 0.75)',
          }}
        />
      )}
    </div>
  )
}
