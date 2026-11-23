import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { Card, ClassRow, LoadState, useMinute } from '@/components/portal-parts';
import { BackLink, Body, Button, Screen, Title } from '@/components/ui';
import { scheduleStore, useResource } from '@/lib/portal-data';
import { campusNow, classesOn, dayLabel, formatTime, nextClass, scheduleName, weekDays } from '@/lib/schedule';
import { useSession } from '@/lib/session';
import type { ClassItem } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

/**
 * "My schedule" (students) or "My classes" (faculty), like the web portal (docs/schedule.md): today first, then the
 * rest of the week. Each class with a room on the map has Directions. Tap a class to edit or delete it. The last copy
 * shows offline (portal-data.ts).
 */
export default function ScheduleScreen() {
  const { user } = useSession();
  const schedule = useResource(scheduleStore);
  useMinute();

  const name = scheduleName(user) ?? 'My schedule';
  const isStudent = user?.role === 'student';
  const classes = schedule.data?.classes ?? [];
  const today = campusNow().day;
  const todays = classesOn(classes, today);
  const next = nextClass(classes);
  const edit = (item: ClassItem) => router.push({ pathname: '/portal/class-form', params: { id: String(item.id) } });
  const add = () => router.push('/portal/class-form');
  const importCor = () => router.push('/portal/schedule-import');

  return (
    <Screen onRefresh={() => scheduleStore.load(true)} refreshing={schedule.loading && schedule.data !== null}>
      <BackLink label="Home" />
      <View style={styles.head}>
        <Title>{name}</Title>
        <Text style={type.small}>{schedule.data ? (schedule.data.term ?? 'No current term yet') : ' '}</Text>
      </View>

      <LoadState resource={schedule} what="schedule" onRetry={() => scheduleStore.load(true)} />

      {schedule.data && classes.length === 0 && (
        <Card>
          <View style={styles.empty}>
            <Text style={type.heading}>No classes yet</Text>
            <Body muted>
              {isStudent
                ? 'Import your COR to fill in your whole week at once, or add your classes one at a time.'
                : 'Add the classes you teach this term, one meeting at a time (a class that meets twice a week is added twice).'}{' '}
              Each class with a room on the campus map gets Directions.
            </Body>
            {isStudent && <Button title="Import from COR" onPress={importCor} />}
            <Button title={isStudent ? 'Add a class' : 'Add your first class'} variant={isStudent ? 'quiet' : 'primary'} onPress={add} />
          </View>
        </Card>
      )}

      {classes.length > 0 && (
        <>
          <View style={styles.actions}>
            <Button title="Add a class" onPress={add} />
            {isStudent && <Button title="Import from COR" variant="quiet" onPress={importCor} />}
          </View>

          <View style={styles.today}>
            <View style={styles.todayHead}>
              <Text style={styles.todayLabel} accessibilityRole="header">
                Today
              </Text>
              <Text style={styles.todayDay}>{dayLabel(today)}</Text>
            </View>
            {todays.length > 0 ? (
              todays.map((item, index) => <ClassRow key={item.id} item={item} first={index === 0} onPress={() => edit(item)} />)
            ) : (
              <View style={styles.todayEmpty}>
                <Text style={type.body}>No classes today.</Text>
                {next && (
                  <Text style={type.small}>
                    Next: {next.class.subject}, {next.when} at {formatTime(next.class.starts_at)}
                  </Text>
                )}
              </View>
            )}
          </View>

          <Text style={type.heading} accessibilityRole="header">
            This week
          </Text>
          {weekDays(classes)
            .filter((day) => day !== today)
            .map((day) => {
              const dayClasses = classesOn(classes, day);
              return (
                <Card
                  key={day}
                  title={dayLabel(day)}
                  action={<Text style={type.small}>{dayClasses.length === 0 ? 'No classes' : `${dayClasses.length} ${dayClasses.length === 1 ? 'class' : 'classes'}`}</Text>}>
                  {dayClasses.map((item, index) => (
                    <ClassRow key={item.id} item={item} first={index === 0} onPress={() => edit(item)} />
                  ))}
                  {dayClasses.length === 0 && <View style={styles.gap} />}
                </Card>
              );
            })}
          <Text style={[type.small, styles.center]}>Tap a class to change or delete it.</Text>
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  head: { gap: 2 },
  empty: { padding: space.md, gap: space.md },
  actions: { gap: space.sm },
  today: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.secondary, overflow: 'hidden' },
  todayHead: { flexDirection: 'row', alignItems: 'baseline', gap: space.sm, paddingHorizontal: space.md, paddingVertical: 12, backgroundColor: colors.primary },
  todayLabel: { fontFamily: fonts.bold, fontSize: 20, color: colors.white },
  todayDay: { fontFamily: fonts.medium, fontSize: 16, color: colors.secondary },
  todayEmpty: { padding: space.md, gap: 4 },
  gap: { height: 4 },
  center: { textAlign: 'center' },
});
