import { router } from 'expo-router';

import { ActiveAlertPanel, useLiveAlerts } from '@/components/alert-admin/active-alert-panel';
import { LoadState } from '@/components/portal-parts';
import { BackLink, Body, Button, Screen, Title } from '@/components/ui';
import { alertsStore } from '@/lib/admin';
import { useResource } from '@/lib/portal-data';

/**
 * "Emergency alerts" for faculty and staff the admin trusts (Portal\AlertController on the web): send an alert (it goes
 * out at once) and end the active one. Editing the wording stays with the administrator.
 */
export default function PortalAlerts() {
  const overview = useResource(alertsStore);
  useLiveAlerts();

  return (
    <Screen onRefresh={() => alertsStore.load(true)} refreshing={false}>
      <BackLink label="Home" />
      <Title>Emergency alerts</Title>
      <Body muted>The administrator trusts you to send alerts. They go out at once, and every alert shows who sent it.</Body>
      <LoadState resource={overview} what="alerts" onRetry={() => alertsStore.load(true)} />
      <ActiveAlertPanel overview={overview.data} area="/portal" />
      <Button title="Recent alerts" variant="quiet" onPress={() => router.push('/portal/alert-history')} />
    </Screen>
  );
}
