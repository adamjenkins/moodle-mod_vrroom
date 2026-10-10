// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Markers floating on a 360° photo: the round "go to the next photo" marker a portal becomes in a
 * panorama scene (a disc with a ring and an arrow; the scene builder adds the room name above it).
 *
 * Only three.js objects passed in, no Moodle imports, so it runs in node tests too.
 *
 * @module     vrroomruntime_builtin/markers
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Radius of the portal marker's disc (m). */
export const PORTAL_MARKER_RADIUS = 0.35;

/** Colour of the portal marker (as the door portal's plane). */
const COLOUR = 0x6ea8fe;

/** Colour of its ring and arrow. */
const FOREGROUND = 0xffffff;

/**
 * Build the round portal marker of a panorama scene, centred on the group origin and facing +z
 * (the scene builder turns the group to face the centre of the photo).
 *
 * @param {Object} THREE three.js namespace.
 * @param {Object} [options] {radius (m)}
 * @returns {Object} {group, disc (the pulsing mesh, emissive material), ring, arrow, hit, labelY}
 */
export const buildPortalMarker = (THREE, options = {}) => {
    const radius = Number(options.radius) > 0 ? Number(options.radius) : PORTAL_MARKER_RADIUS;
    const group = new THREE.Group();
    group.name = 'vrroom-portal-marker';

    const disc = new THREE.Mesh(
        new THREE.CircleGeometry(radius, 48),
        new THREE.MeshStandardMaterial({
            color: COLOUR, emissive: COLOUR, emissiveIntensity: 0.6,
            transparent: true, opacity: 0.45, side: THREE.DoubleSide, depthWrite: false,
        })
    );
    disc.name = 'vrroom-portal-disc';
    group.add(disc);

    const ring = new THREE.Mesh(
        new THREE.RingGeometry(radius * 0.9, radius, 48),
        new THREE.MeshBasicMaterial({color: FOREGROUND, side: THREE.DoubleSide, transparent: true, opacity: 0.95})
    );
    ring.name = 'vrroom-portal-ring';
    ring.position.z = 0.005;
    group.add(ring);

    // An arrow pointing up (forward, "go there"), drawn as a flat shape.
    const s = radius;
    const shape = new THREE.Shape();
    shape.moveTo(0, 0.55 * s);
    shape.lineTo(0.4 * s, 0.1 * s);
    shape.lineTo(0.15 * s, 0.1 * s);
    shape.lineTo(0.15 * s, -0.5 * s);
    shape.lineTo(-0.15 * s, -0.5 * s);
    shape.lineTo(-0.15 * s, 0.1 * s);
    shape.lineTo(-0.4 * s, 0.1 * s);
    shape.closePath();
    const arrow = new THREE.Mesh(
        new THREE.ShapeGeometry(shape),
        new THREE.MeshBasicMaterial({color: FOREGROUND, side: THREE.DoubleSide, transparent: true, opacity: 0.95})
    );
    arrow.name = 'vrroom-portal-arrow';
    arrow.position.z = 0.01;
    group.add(arrow);

    // A larger invisible target, so the marker is easy to hit from afar.
    const hit = new THREE.Mesh(
        new THREE.CircleGeometry(radius * 1.4, 16),
        new THREE.MeshBasicMaterial({visible: false, side: THREE.DoubleSide})
    );
    hit.name = 'vrroom-portal-hit';
    group.add(hit);

    return {group: group, disc: disc, ring: ring, arrow: arrow, hit: hit, labelY: radius + 0.22};
};
