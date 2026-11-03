package main

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

type childProcess struct {
	name string
	cmd  *exec.Cmd
	done chan struct{}

	mu  sync.Mutex
	err error
}

func (p *childProcess) exitError() (error, bool) {
	select {
	case <-p.done:
		p.mu.Lock()
		defer p.mu.Unlock()
		if p.err == nil {
			return fmt.Errorf("процесс %s завершился", p.name), true
		}
		return fmt.Errorf("процесс %s завершился: %w", p.name, p.err), true
	default:
		return nil, false
	}
}

func (p *childProcess) stop() {
	select {
	case <-p.done:
		return
	default:
	}
	_ = p.cmd.Process.Signal(syscall.SIGTERM)
	timer := time.NewTimer(5 * time.Second)
	defer timer.Stop()
	select {
	case <-p.done:
	case <-timer.C:
		_ = p.cmd.Process.Kill()
		<-p.done
	}
}

func (e *Engine) spawn(hop int, tool string, args []string, stdin string, extraEnv []string) error {
	path, err := locateTool(tool)
	if err != nil {
		return err
	}
	logPath := filepath.Join(e.runDir, fmt.Sprintf("hop%d.log", hop+1))
	file, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return fmt.Errorf("журнал узла: %w", err)
	}
	_, _ = fmt.Fprintf(file, "\n=== %s %s ===\n", tool, time.Now().Format(time.RFC3339))
	command := exec.Command(path, args...)
	command.Dir = e.runDir
	command.Stdout = file
	command.Stderr = file
	command.Stdin = nil
	if stdin != "" {
		command.Stdin = strings.NewReader(stdin)
	} else {
		command.Stdin = io.Reader(nil)
	}
	command.Env = append(os.Environ(), extraEnv...)
	if err := command.Start(); err != nil {
		_ = file.Close()
		return fmt.Errorf("запуск %s: %w", tool, err)
	}
	_ = file.Close()
	child := &childProcess{name: tool, cmd: command, done: make(chan struct{})}
	go func() {
		err := command.Wait()
		child.mu.Lock()
		child.err = err
		child.mu.Unlock()
		close(child.done)
	}()
	for len(e.processes) <= hop {
		e.processes = append(e.processes, nil)
	}
	e.processes[hop] = append(e.processes[hop], child)
	e.log("узел %d: запущен %s (pid %d), журнал %s", hop+1, tool, command.Process.Pid, logPath)
	return nil
}

func locateTool(tool string) (string, error) {
	envNames := map[string]string{
		"cep-argon4": "CEP_ARGON4", "openconnect": "CEP_OPENCONNECT",
		"openvpn": "CEP_OPENVPN", "xray": "CEP_XRAY", "sing-box": "CEP_SING_BOX",
	}
	if envName := envNames[tool]; envName != "" {
		if path := os.Getenv(envName); path != "" {
			return path, nil
		}
	}
	if tool == "cep-argon4" {
		if executable, err := os.Executable(); err == nil {
			candidate := filepath.Join(filepath.Dir(executable), tool)
			if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
				return candidate, nil
			}
		}
	}
	path, err := exec.LookPath(tool)
	if err != nil {
		return "", fmt.Errorf("не найден %s: установите его или укажите путь через %s", tool, envNames[tool])
	}
	return path, nil
}
