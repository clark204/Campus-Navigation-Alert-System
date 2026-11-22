import { memo } from 'react';
import Svg, { Polygon, Polyline, Rect } from 'react-native-svg';

import { MAP_COLORS, svgPoints, type CampusMap, type Projection } from '@/lib/campus-map';

type Props = {
  map: CampusMap;
  projection: Projection;
  /** [minX, minY, width, height] in projected meters. */
  viewBox: [number, number, number, number];
  width: number;
  height: number;
  /** Building codes an active emergency names (alarm red, thick dark edge). */
  affected: string[];
  /** Line widths are in meters; a mini-map draws them thicker so they show at all. */
  lineScale?: number;
  /** Ground walkways fade while navigation shows an upper floor. */
  walkwayOpacity?: number;
};

const FILL: Record<string, string> = {
  campus: MAP_COLORS.campus,
  evacuation: MAP_COLORS.evacuation,
  facility: MAP_COLORS.facility,
  building: MAP_COLORS.building,
};

// Draw order like the web map: campus, evacuation area, facilities, buildings, then walkways and gates on top.
const ORDER = ['campus', 'evacuation', 'facility', 'building'];

/** The campus drawn with react-native-svg from GET /api/map. No tiles. */
export const CampusSvg = memo(function CampusSvg({ map, projection, viewBox, width, height, affected, lineScale = 1, walkwayOpacity = 1 }: Props) {
  const buildings = [...map.buildings].sort((a, b) => ORDER.indexOf(a.type) - ORDER.indexOf(b.type));
  return (
    <Svg width={width} height={height} viewBox={viewBox.join(' ')}>
      <Rect x={viewBox[0]} y={viewBox[1]} width={viewBox[2]} height={viewBox[3]} fill={MAP_COLORS.ground} />
      {buildings.map((building) => {
        const hit = affected.includes(building.id);
        return (
          <Polygon
            key={building.id}
            points={svgPoints(projection, building.ring)}
            fill={hit ? MAP_COLORS.affected : (FILL[building.type] ?? MAP_COLORS.building)}
            stroke={hit ? MAP_COLORS.affectedEdge : building.type === 'campus' ? 'none' : 'rgba(0,0,0,0.12)'}
            strokeWidth={hit ? 1.6 * lineScale : 0.3 * lineScale}
            strokeLinejoin="round"
          />
        );
      })}
      {/* Ground floor only: upper-floor corridors run inside the buildings. */}
      {map.paths
        .filter((path) => path.floor === 1)
        .map((path) => (
          <Polyline
            key={path.id}
            points={svgPoints(projection, path.line)}
            fill="none"
            strokeOpacity={walkwayOpacity}
            stroke={path.type === 'gate' ? MAP_COLORS.gate : MAP_COLORS.walkway}
            strokeWidth={(path.type === 'gate' ? 3 : 2) * lineScale}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        ))}
    </Svg>
  );
});
