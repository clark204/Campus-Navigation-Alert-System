import { activateKeepAwakeAsync, deactivateKeepAwake } from 'expo-keep-awake';

import {
  attachPoint,
  buildManeuvers,
  CAUGHT_UP_METERS,
  createFixFilter,
  createPromptPlanner,
  createSimulator,
  createTracker,
  currentLeg,
  DEFAULT_STRIDE,
  doorHeading,
  findRoute,
  GPS_MAX_ACCURACY,
  legIntro,
  legLabel,
  legLine,
  meters,
  nearestOnEdges,
  ordinal,
  projectOnRoute,
  routeLegs,
  SIM_INTERVAL_MS,
  type Fix,
  type GpsStatus,
  type Heading,
  type Leg,
  type LngLat,
  type Maneuver,
  type Navigation,
  type Place,
  type PromptPlanner,
  type Route,
  type Simulator,
  type Tracker,
} from './engine';
import type { LocationStatus } from './location';
import type { Destination } from './places';
import { startStepSensor, type StepSensor } from './steps';
import { voice } from './voice';

// The navigation session of CNAS-web navigation/main.js (start, applyFix, reroute, manual mode, render), without the
// DOM. The constants and texts are main.js's; keep them the same so the app and the website behave alike.
const WALKING_SPEED = 1.2; // m/s, for the time estimate
const OFF_CAMPUS_METERS = 40;
const GPS_WAIT_MS = 20000;
const RECENT_STEP_MS = 2000;
const WRONG_WAY_TEXT = "You're going the wrong way, turn around";
const REROUTING_TEXT = 'Rerouting';
const NO_REROUTE_TEXT = 'No other way from here. Go back to the marked route';
const REROUTE_GRACE_FIXES = 3;
const REROUTE_NOTE_MS = 4000;
const LEG_FIT_MS = 4000;
// The app's own wording for what main.js says about the browser.
const LOCATION_DENIED_NOTE = "Location access is turned off for CNAS, so the dot can't follow you. Allow location for CNAS in your phone's settings.";
const LOCATION_OFF_NOTE = "Your phone's location is turned off, so the dot can't follow you. Turn on location, then start again.";
const noFixNote = (accuracy: number | null) =>
  accuracy
    ? `Your location is only accurate to about ${Math.round(accuracy)} m here (it needs ${GPS_MAX_ACCURACY} m or better). Step into the open and the dot will start following you.`
    : "Your phone hasn't found your location yet. Step into the open and the dot will start following you.";
const KEEP_AWAKE_TAG = 'cnas-navigation';

export const formatDistance = (m: number) => (m < 10 ? `${Math.max(0, Math.round(m))} m` : `${Math.round(m / 5) * 5} m`);
export const formatMinutes = (m: number) => `${Math.max(1, Math.round(m / WALKING_SPEED / 60))} min`;

/** Where a trip starts: the walkway where the person is ("My location"), or a gate or room (picked, or a QR code). */
export type Start = { kind: 'here'; name: string; nodeId: string } | { kind: 'place'; name: string; nodeId: string; place: Place };

export type Plan = { from: Start; to: Destination; route: Route; maneuvers: Maneuver[]; legs: Leg[] };

/** Whether "My location" can start a route: a fix precise enough, near a walkway. No graph change until it is used. */
export function checkHere(navigation: Navigation, fix: Fix | null): { ok: true; coord: LngLat } | { ok: false; reason: 'none' | 'imprecise' | 'far'; accuracy: number | null } {
  if (!fix) return { ok: false, reason: 'none', accuracy: null };
  if (!(fix.coords.accuracy <= GPS_MAX_ACCURACY)) return { ok: false, reason: 'imprecise', accuracy: fix.coords.accuracy };
  const coord: LngLat = [fix.coords.longitude, fix.coords.latitude];
  const hit = nearestOnEdges(navigation.graph, coord);
  if (!hit || hit.distance > OFF_CAMPUS_METERS) return { ok: false, reason: 'far', accuracy: fix.coords.accuracy };
  return { ok: true, coord };
}

/** "My location" as a start: the closest point on the ground-floor walkways, as a reroute does (position.js). */
export function hereStart(navigation: Navigation, coord: LngLat): Start | null {
  const start = attachPoint(navigation.graph, coord);
  return start ? { kind: 'here', name: 'your location', nodeId: start.nodeId } : null;
}

export const placeStart = (place: Place): Start => ({ kind: 'place', name: place.name, nodeId: place.nodeId, place });

/** The route and its directions, like updatePlan in main.js. */
export function planRoute(navigation: Navigation, from: Start, to: Destination): { plan: Plan } | { error: string } {
  if (from.kind === 'place' && to.kind === 'place' && from.place.id === to.id) return { error: 'You are already there. Pick a different destination.' };
  const route = findRoute(navigation.graph, from.nodeId, to.targets, to.options?.());
  if (!route) return { error: to.options ? 'No safe route between these places while the emergency alert is active.' : 'No walkable route between these places has been mapped yet.' };
  const maneuvers = buildManeuvers(route, navigation.graph, {
    landmarks: navigation.data.landmarks,
    buildings: navigation.data.buildings,
    originName: from.name,
    destinationName: to.name,
    // From "My location" the way the person faces isn't known yet: the first instruction names a landmark. From a
    // room it is out of its door (doorHeading), as on the web.
    ...(from.kind === 'here' ? { heading: null } : {}),
  });
  return { plan: { from, to, route, maneuvers, legs: routeLegs(route, maneuvers, { destinationName: to.name }) } };
}

export type Manual = false | 'no fix' | 'off';

/** What the navigation screen shows. A new object after every change. */
export type SessionView = {
  plan: Plan;
  dot: LngLat;
  heading: Heading;
  next: Maneuver;
  /** "Then: …" */
  after: string | null;
  distance: string;
  remaining: string;
  eta: string;
  legLabel: string;
  /** Where the pin is: the stairs of this leg, or the destination on the last one. Null on a route to safety's end. */
  pin: LngLat | null;
  /** Changes when the pin moves (it drops in again). */
  pinKey: number;
  /** The rest of the current leg. */
  line: LngLat[];
  /** Changes when a new route or leg should draw in. */
  lineKey: number;
  floor: number;
  waitingAt: Maneuver | null;
  wrongWay: boolean;
  manual: Manual;
  note: string | null;
  rerouteNote: string | null;
  /** Show this part of the route (after a floor is confirmed) until `until`, when it goes back to null. */
  hold: { until: number; coords: LngLat[] } | null;
  arrived: string | null;
  ended: boolean;
  debug: {
    accuracy: number | null;
    best: number | null;
    gps: GpsStatus | null;
    location: LocationStatus;
    steps: number;
    stepSensor: boolean;
    offRoute: number;
    offRouteStreak: number;
    reroutes: number;
    simulating: boolean;
    source: string;
  };
};

export class NavigationSession {
  plan: Plan;
  view: SessionView;
  private tracker: Tracker;
  private planner: PromptPlanner;
  private legs: Leg[];
  private legIndex = 0;
  private pinPlaced = false;
  private pinKey = 0;
  private lineKey = 0;
  private manual: Manual = false;
  private note: string | null = null;
  private gpsOffNote: string | null = null;
  private rerouteNote: string | null = null;
  private hold: SessionView['hold'] = null;
  private arrived: string | null = null;
  private ended = false;
  private reroutes = 0;
  private hadUsableFix = false;
  private bestAccuracy: number | null = null;
  private lastAccuracy: number | null = null;
  private startedAt = Date.now();
  private gpsTimer: ReturnType<typeof setTimeout> | null = null;
  private rerouteTimer: ReturnType<typeof setTimeout> | null = null;
  private holdTimer: ReturnType<typeof setTimeout> | null = null;
  private simTimer: ReturnType<typeof setInterval> | null = null;
  private steps: StepSensor;
  private sim: Simulator | null = null;
  private listeners = new Set<() => void>();

  constructor(
    private navigation: Navigation,
    plan: Plan,
    private location: LocationStatus,
  ) {
    this.plan = plan;
    this.legs = plan.legs;
    // beginNavigation: the opening line, with the first prompt that is due.
    this.planner = createPromptPlanner(plan.maneuvers);
    const opening = this.planner.next(0);
    voice.say([`Starting navigation to ${plan.to.name}`, legIntro(this.legs, this.legs[0]), plan.maneuvers[0].now, opening?.text].filter(Boolean).join('. '));

    const facing = doorHeading(plan.route);
    this.tracker = this.newTracker(plan.route, plan.maneuvers, { startHeading: facing === null ? null : { value: facing, source: 'door' } });
    this.steps = startStepSensor(() => this.applyStep());
    activateKeepAwakeAsync(KEEP_AWAKE_TAG).catch(() => {});
    this.view = this.snapshot();
    this.render();
    this.setLocation(location);
  }

  subscribe = (listener: () => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  private emit() {
    this.view = this.snapshot();
    this.listeners.forEach((listener) => listener());
  }

  private newTracker(route: Route, maneuvers: Maneuver[], options: Partial<Parameters<typeof createTracker>[2]> = {}) {
    const tracker = createTracker(route, maneuvers, { stride: DEFAULT_STRIDE, graph: this.navigation.graph, ...options });
    tracker.onChange = () => this.render();
    return tracker;
  }

  /** The location watch changed (main.js start: denied, or no usable fix within GPS_WAIT_MS, means manual mode). */
  setLocation(status: LocationStatus) {
    this.location = status;
    if (this.ended) return;
    if (status === 'denied') this.enterManualMode(LOCATION_DENIED_NOTE);
    else if (status === 'off') this.enterManualMode(LOCATION_OFF_NOTE);
    else if (status === 'on' || status === 'waiting') {
      if (this.gpsTimer) clearTimeout(this.gpsTimer);
      this.gpsTimer = setTimeout(() => {
        if (!this.ended && !this.hadUsableFix && !this.sim) this.enterManualMode(noFixNote(this.bestAccuracy), 'no fix');
      }, Math.max(0, GPS_WAIT_MS - (Date.now() - this.startedAt)));
    }
    this.emit();
  }

  /** A GPS fix: the main source of position and direction. Simulated fixes (debug) take the same path. */
  applyFix(fix: Fix, { simulated = false } = {}) {
    if (this.ended || simulated !== (this.sim !== null)) return;
    const tracker = this.tracker;
    const recentSteps = !simulated && this.steps.receiving ? Date.now() - tracker.lastStepAt < RECENT_STEP_MS : null;
    const usable = fix.coords.accuracy <= GPS_MAX_ACCURACY;
    if (!simulated) {
      this.lastAccuracy = fix.coords.accuracy;
      this.bestAccuracy = Math.min(this.bestAccuracy ?? Infinity, fix.coords.accuracy);
      if (usable) this.hadUsableFix = true;
    }
    if (usable && this.manual === 'no fix') this.exitManualMode();
    if (this.manual) {
      tracker.lastGps = { accuracy: fix.coords.accuracy, speed: null, moving: false, used: false, reason: 'not precise enough yet', offRoute: 0, rerouteDue: false, wrongWayStarted: false };
      this.emit();
      return;
    }
    const result = tracker.gps(fix, { recentSteps });
    if (this.ended) return;
    // Said after the fix's own render, so it replaces any turn prompt that render queued.
    if (result.wrongWayStarted) voice.say(WRONG_WAY_TEXT);
    if (result.rerouteDue) this.reroute();
  }

  /** A detected step fills in between fixes (tracker.js). */
  applyStep() {
    if (!this.ended && !this.manual) this.tracker.step();
  }

  imHere() {
    this.tracker.imHere();
  }

  confirmFloor() {
    this.tracker.confirmFloor();
  }

  /**
   * A CNAS QR code scanned on the way. On the route it is "I'm here" at that spot (stairs before it count as
   * climbed); anywhere else the route starts again from there, as the web does when a checkpoint reopens /navigate.
   */
  scanAt(place: Place) {
    if (this.ended) return;
    const tracker = this.tracker;
    const index = tracker.route.nodes.findIndex((node) => node.id === place.nodeId);
    const node = this.navigation.graph.nodes.get(place.nodeId);
    if (!node) return;
    if (index !== -1) {
      for (const m of this.plan.maneuvers) {
        if (m.type === 'stairs' && tracker.route.nodes.findIndex((n) => n.id === m.nodeId) < index) tracker.confirmedStairs.add(m.index);
      }
      tracker.source = 'manual';
      tracker.setProgress(tracker.route.along[index]);
      tracker.filter.reset(tracker.position());
      return;
    }
    const route = findRoute(this.navigation.graph, place.nodeId, this.plan.to.targets, this.plan.to.options?.());
    if (!route) {
      voice.say(NO_REROUTE_TEXT);
      this.showRerouteNote(NO_REROUTE_TEXT);
      return;
    }
    const maneuvers = buildManeuvers(route, this.navigation.graph, {
      landmarks: this.navigation.data.landmarks,
      buildings: this.navigation.data.buildings,
      originName: place.name,
      destinationName: this.plan.to.name,
    });
    const facing = doorHeading(route);
    this.replaceRoute(route, maneuvers, `Starting from ${place.name}`, {
      filter: createFixFilter({ start: node.coord }),
      startHeading: facing === null ? null : { value: facing, source: 'door' },
    });
  }

  /** The person took another way: new directions from where the dot is to the same destination (main.js reroute). */
  private reroute() {
    const here = this.tracker.position();
    // The new directions start from the way the person is walking (or name a landmark when that is unknown).
    const heading = this.tracker.heading;
    const start = attachPoint(this.navigation.graph, here);
    const route = start ? findRoute(this.navigation.graph, start.nodeId, this.plan.to.targets, this.plan.to.options?.()) : null;
    if (!route) {
      this.tracker.graceFixes = REROUTE_GRACE_FIXES * 3;
      voice.say(NO_REROUTE_TEXT);
      this.showRerouteNote(NO_REROUTE_TEXT);
      return;
    }
    const maneuvers = buildManeuvers(route, this.navigation.graph, {
      landmarks: this.navigation.data.landmarks,
      buildings: this.navigation.data.buildings,
      destinationName: this.plan.to.name,
      heading: heading.value,
    });
    this.reroutes++;
    this.replaceRoute(route, maneuvers, REROUTING_TEXT, { filter: this.tracker.filter, graceFixes: REROUTE_GRACE_FIXES, startHeading: heading });
    this.showRerouteNote(`${REROUTING_TEXT}…`);
  }

  private replaceRoute(route: Route, maneuvers: Maneuver[], say: string, options: Partial<Parameters<typeof createTracker>[2]>) {
    const legs = routeLegs(route, maneuvers, { destinationName: this.plan.to.name });
    this.plan = { ...this.plan, route, maneuvers, legs };
    this.legs = legs;
    this.planner = createPromptPlanner(maneuvers);
    // Reroutes only happen on the ground, so the new route starts its own first leg.
    this.legIndex = 0;
    this.pinPlaced = false;
    this.lineKey++;
    const opening = this.planner.next(0);
    voice.say([say, legIntro(legs, legs[0]), maneuvers[0].now, opening?.text].filter(Boolean).join('. '));
    const previous = this.tracker;
    this.tracker = this.newTracker(route, maneuvers, options);
    this.tracker.steps = previous.steps;
    this.tracker.lastStepAt = previous.lastStepAt;
    this.tracker.lastGps = previous.lastGps;
    this.render();
  }

  private showRerouteNote(text: string) {
    this.rerouteNote = text;
    if (this.rerouteTimer) clearTimeout(this.rerouteTimer);
    this.rerouteTimer = setTimeout(() => {
      this.rerouteNote = null;
      this.emit();
    }, REROUTE_NOTE_MS);
    this.emit();
  }

  // Without GPS the dot can't follow the person, so they tap "Next" at each instruction. 'no fix' while waiting for a
  // usable fix (a later one ends manual mode), 'off' when location can't be had at all.
  private enterManualMode(note: string, reason: Exclude<Manual, false> = 'off') {
    if (reason === 'off') this.gpsOffNote = note;
    if (this.manual || this.sim) return;
    this.manual = reason;
    this.note = `${note} ${reason === 'off' ? 'Tap' : 'Until then, tap'} “Next” each time you reach the instruction shown.`;
    this.tracker.clearWrongWay();
  }

  private exitManualMode() {
    this.manual = false;
    this.note = null;
    this.render();
  }

  // main.js render(): one floor at a time (legs), the pin on the leg's end, the prompts that are due.
  private render() {
    if (this.ended) return;
    const tracker = this.tracker;
    const leg = currentLeg(this.legs, tracker);
    const legChanged = leg.index !== this.legIndex;
    this.legIndex = leg.index;
    if (legChanged || !this.pinPlaced) {
      this.pinPlaced = true;
      this.pinKey++;
    }
    if (legChanged) {
      // A floor was just confirmed: show the whole next part, then follow the dot again.
      this.lineKey++;
      this.hold = { until: Date.now() + LEG_FIT_MS, coords: [...legLine(tracker.route, leg, tracker.progress).geometry.coordinates, leg.target.coord] };
      if (this.holdTimer) clearTimeout(this.holdTimer);
      this.holdTimer = setTimeout(() => {
        this.hold = null;
        this.emit();
      }, LEG_FIT_MS);
    }
    if (tracker.arrived) {
      this.finish(true);
      return;
    }
    // While on the stairs only the stairs prompt itself may play. Only a heading from GPS movement may skip a turn
    // the person is already walking out of.
    const heading = tracker.heading;
    const stairs = tracker.waitingAt;
    const walking = heading.source === 'gps' ? heading.value : null;
    const prompt = !stairs || !this.planner.said.has(`near:${stairs.index}`) ? this.planner.next(tracker.progress, walking) : null;
    const intro = legChanged ? legIntro(this.legs, leg) : null;
    if (intro) voice.say([intro, prompt?.text].filter(Boolean).join('. '));
    else if (prompt) voice.say(prompt.text);
    this.emit();
  }

  /** End: arrived, or the person tapped End. */
  finish(arrived: boolean) {
    if (this.ended) return;
    const arrive = this.tracker.arriveManeuver;
    if (arrived && !this.planner.said.has(`near:${arrive.index}`)) voice.say(arrive.now);
    if (!arrived) voice.silence();
    this.arrived = arrived ? arrive.text : null;
    this.stop();
    this.emit();
  }

  stop() {
    this.ended = true;
    if (this.gpsTimer) clearTimeout(this.gpsTimer);
    if (this.rerouteTimer) clearTimeout(this.rerouteTimer);
    if (this.holdTimer) clearTimeout(this.holdTimer);
    if (this.simTimer) clearInterval(this.simTimer);
    this.sim = null;
    this.steps.stop();
    deactivateKeepAwake(KEEP_AWAKE_TAG).catch(() => {});
  }

  // ---------- Debug (development builds only): a fake GPS walk through applyFix, like ?debug=1 on the web.
  setSimulating(on: boolean) {
    if (this.ended) return;
    if (this.simTimer) clearInterval(this.simTimer);
    this.simTimer = null;
    if (!on) {
      this.sim = null;
      if (this.gpsOffNote) this.enterManualMode(this.gpsOffNote);
      else if (!this.hadUsableFix && Date.now() - this.startedAt >= GPS_WAIT_MS) this.enterManualMode(noFixNote(this.bestAccuracy), 'no fix');
      this.emit();
      return;
    }
    if (this.manual) {
      this.manual = false;
      this.note = null;
    }
    this.sim = createSimulator({
      emit: (fix) => this.applyFix(fix, { simulated: true }),
      caughtUp: (coord) => meters(this.tracker.filter.coord ?? coord, coord) < CAUGHT_UP_METERS,
    });
    this.sim.place(this.tracker.position());
    this.sim.stop();
    this.simTimer = setInterval(() => this.sim?.tick(), SIM_INTERVAL_MS);
    this.render();
  }

  /** Simulated walk along the rest of the route. */
  simulateWalk() {
    this.sim?.walk(this.tracker.remainingLine().geometry.coordinates.slice(1));
  }

  /** Simulated walk to the nearest walkway point at least 20 m from the route: a reroute after a few fixes. */
  simulateWalkOff() {
    const here = this.tracker.position();
    let best: { coord: LngLat; distance: number } | null = null;
    for (const node of this.navigation.graph.nodes.values()) {
      if (node.kind !== 'path' || node.floor !== 1 || projectOnRoute(this.tracker.route, node.coord).distance < 20) continue;
      const distance = meters(here, node.coord);
      if (!best || distance < best.distance) best = { coord: node.coord, distance };
    }
    if (best) this.sim?.walkTo(best.coord);
  }

  private snapshot(): SessionView {
    const tracker = this.tracker;
    const leg = currentLeg(this.legs, tracker);
    const next = tracker.next;
    const after = this.plan.maneuvers[next.index + 1];
    const stairs = tracker.waitingAt;
    return {
      plan: this.plan,
      dot: tracker.position(),
      heading: tracker.heading,
      next,
      after: after ? after.text : null,
      distance: stairs ? 'Now' : formatDistance(tracker.distanceToNext),
      remaining: formatDistance(tracker.remaining),
      eta: formatMinutes(tracker.remaining),
      legLabel: legLabel(this.legs, leg),
      pin: this.plan.to.showPin === false && leg.target.kind === 'destination' ? null : leg.target.coord,
      pinKey: this.pinKey,
      line: legLine(tracker.route, leg, tracker.progress).geometry.coordinates,
      lineKey: this.lineKey,
      floor: stairs ? (stairs.fromFloor ?? 1) : tracker.floorAt(),
      waitingAt: stairs,
      wrongWay: tracker.wrongWay,
      manual: this.manual,
      note: this.note,
      rerouteNote: this.rerouteNote,
      hold: this.hold,
      arrived: this.arrived,
      ended: this.ended,
      debug: {
        accuracy: this.lastAccuracy,
        best: this.bestAccuracy,
        gps: tracker.lastGps,
        location: this.location,
        steps: tracker.steps,
        stepSensor: this.steps.receiving,
        offRoute: tracker.offRoute,
        offRouteStreak: tracker.offRouteStreak,
        reroutes: this.reroutes,
        simulating: this.sim !== null,
        source: tracker.source,
      },
    };
  }
}

/** "I'm on the 2nd floor". */
export const floorButton = (stairs: Maneuver) => `I'm on the ${ordinal(stairs.toFloor ?? 2)} floor`;
