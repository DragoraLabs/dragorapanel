# Node Agent Egg Support

The Go agent needs these changes to support egg-defined server types.

## 1. `internal/database/mysql.go` — Add EggID + StartupCommand to ServerRow

Add fields:
```go
type ServerRow struct {
    // ... existing ...
    EggID          *int    // egg_id from servers table
    StartupCommand string  // from eggs table, JOINed
}
```

Update queries to JOIN eggs:
```sql
SELECT s.id, s.node_id, s.user_id, s.name, s.egg_id, s.type, s.version,
       s.java_version, COALESCE(s.docker_image, e.docker_image) AS docker_image,
       e.startup_command, s.status, s.memory_mb, s.storage_mb, s.port, ...
FROM servers s
LEFT JOIN eggs e ON e.id = s.egg_id
WHERE s.id = ?
```

Add `GetEgg(eggID int) (*EggRow, error)` for retrieving egg metadata.

## 2. `internal/server/manager.go` — Multi-type jar download

Replace `DownloadPaperJar` with `DownloadServerJar(srvType, version string)`:
- `"paper"` → existing PaperMC CDN scrape
- `"purpur"` → `https://api.purpurmc.org/v2/purpur/{version}/latest/download`
- `"vanilla"` → Mojang manifest API (`https://launchermeta.mojang.com/mc/game/version_manifest.json`)
- `"fabric"` → Fabric meta API (`https://meta.fabricmc.net/v2/versions/loader/{version}/1.0.0/server/jar`)
- Default → return error, expect manual upload

## 3. `internal/api/handlers.go` — Use egg startup command

In `HandleStartServer`, instead of hardcoded `java -jar`:
```go
startupCmd := s.StartupCommand  // from egg
if startupCmd == "" {
    startupCmd = "java -Xms128M -Xmx{{MEMORY}}M -jar server.jar nogui"
}
// Replace {{MEMORY}}, {{VERSION}} placeholders
startupCmd = strings.ReplaceAll(startupCmd, "{{MEMORY}}", strconv.Itoa(s.MemoryMB))
startupCmd = strings.ReplaceAll(startupCmd, "{{VERSION}}", s.Version)
```

Pass the processed startup command to `h.Runtime.StartServer(...)` as a new parameter.

## 4. `internal/runtime/docker.go` — Accept startup command

Update `StartServer` signature:
```go
func (cm *ContainerManager) StartServer(serverID, memoryMB, port int, containerVersion, imageName, startupCmd string) error
```

Use `startupCmd` as the container's command instead of the default `java -jar server.jar`. Pass it via Docker's `Cmd` field.
