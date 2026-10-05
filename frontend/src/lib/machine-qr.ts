export function buildMachineQrPayload(plantCode: string, machineCode: string) {
  return `MAINTENANCE-MACHINE|1|${encodeURIComponent(plantCode.trim())}|${encodeURIComponent(machineCode.trim())}`;
}

export function manualMachineQrPayload(value: string) {
  const [plantCode, machineCode, extra] = value.trim().split('/').map((part) => part.trim());
  if (!plantCode || !machineCode || extra !== undefined) return null;
  return buildMachineQrPayload(plantCode, machineCode);
}
