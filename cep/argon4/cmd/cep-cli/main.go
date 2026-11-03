package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"runtime"
	"syscall"
)

func main() {
	log.SetFlags(log.LstdFlags)
	if err := run(os.Args[1:]); err != nil {
		log.Printf("ошибка: %v", err)
		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) == 0 {
		printUsage()
		return fmt.Errorf("не указана команда")
	}
	command, rest := args[0], args[1:]
	flags := flag.NewFlagSet("cep-cli "+command, flag.ContinueOnError)
	profiles := flags.String("profiles", os.Getenv("CEP_PROFILES"), "путь к profiles.json")
	chain := flags.String("chain", "", "имя или UUID цепочки")
	runDir := flags.String("run-dir", "/run/cep-linux", "каталог журналов и временных конфигов")
	output := flags.String("output", "", "файл для результата команды select")
	if err := flags.Parse(rest); err != nil {
		return err
	}
	if *profiles == "" {
		return fmt.Errorf("укажите -profiles /путь/к/profiles.json")
	}
	var store *Store
	var err error
	if command == "wizard" || command == "add-node" {
		store, err = loadStoreForEdit(*profiles)
	} else {
		store, err = loadStore(*profiles)
	}
	if err != nil {
		return err
	}
	switch command {
	case "list":
		for _, item := range store.Chains {
			fmt.Printf("%s\t%s\n", item.Name, item.ID)
			byID := make(map[string]Profile, len(store.Profiles))
			for _, profile := range store.Profiles {
				byID[profile.ID] = profile
			}
			for i, id := range item.Hops {
				profile, ok := byID[id]
				if ok {
					fmt.Printf("  %d. %s (%s)\n", i+1, profile.Name, profile.Proto)
				} else {
					fmt.Printf("  %d. отсутствующий профиль %s\n", i+1, id)
				}
			}
		}
		return nil
	case "validate":
		plan, err := selectPlan(store, *chain)
		if err != nil {
			return err
		}
		fmt.Printf("Цепочка %q корректна: %d узлов\n", plan.ChainName, len(plan.Hops))
		return nil
	case "check":
		plan, err := selectPlan(store, *chain)
		if err != nil {
			return err
		}
		return checkPlan(plan, os.Stdout)
	case "wizard":
		changed, err := runWizard(store, os.Stdin, os.Stdout)
		if err != nil {
			return err
		}
		if changed {
			if err := saveStore(*profiles, store); err != nil {
				return err
			}
			fmt.Printf("Сохранено: %s\n", *profiles)
		}
		return nil
	case "add-node":
		changed, err := runAddNode(store, os.Stdin, os.Stdout)
		if err != nil {
			return err
		}
		if changed {
			if err := saveStore(*profiles, store); err != nil {
				return err
			}
			fmt.Printf("Сохранено: %s\n", *profiles)
		}
		return nil
	case "select":
		if *output == "" {
			return fmt.Errorf("укажите -output FILE для записи UUID выбранной цепочки")
		}
		id, err := runSelectChain(store, os.Stdin, os.Stdout)
		if err != nil {
			return err
		}
		if err := atomicWrite(*output, []byte(id+"\n"), 0o600); err != nil {
			return err
		}
		fmt.Printf("Выбрана цепочка %s\n", id)
		return nil
	case "up":
		plan, err := selectPlan(store, *chain)
		if err != nil {
			return err
		}
		if runtime.GOOS != "linux" {
			return fmt.Errorf("cep-cli up поддерживает только Linux")
		}
		if os.Geteuid() != 0 {
			return fmt.Errorf("для TUN и маршрутов нужен root: запустите через sudo")
		}
		info, err := os.Stat(*profiles)
		if err != nil {
			return err
		}
		if info.Mode().Perm()&0o077 != 0 {
			return fmt.Errorf("profiles.json содержит секреты и доступен другим пользователям: chmod 600 %s", *profiles)
		}
		unlock, err := lockRunDir(*runDir)
		if err != nil {
			return err
		}
		defer unlock()
		ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM, syscall.SIGHUP)
		defer stop()
		return newEngine(plan, *runDir).Run(ctx)
	default:
		printUsage()
		return fmt.Errorf("неизвестная команда %q", command)
	}
}

func lockRunDir(path string) (func(), error) {
	if err := os.MkdirAll(path, 0o700); err != nil {
		return nil, err
	}
	file, err := os.OpenFile(filepath.Join(path, "lock"), os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		_ = file.Close()
		return nil, fmt.Errorf("другой экземпляр cep-cli уже использует %s", path)
	}
	return func() {
		_ = syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
		_ = file.Close()
	}, nil
}

func printUsage() {
	fmt.Fprintln(os.Stderr, `Использование:
  cep-cli wizard -profiles FILE
  cep-cli add-node -profiles FILE
  cep-cli list -profiles FILE
  cep-cli validate -profiles FILE -chain "Имя цепочки"
  cep-cli check -profiles FILE -chain "Имя цепочки"
  cep-cli select -profiles FILE -output FILE
  sudo cep-cli up -profiles FILE -chain "Имя цепочки"

check не считает прямую недоступность узла ошибкой: он может открываться
через предыдущий узел. select интерактивно записывает UUID цепочки.
Внешние программы: cep-argon4, openconnect, openvpn, xray, sing-box.
Пути можно переопределить переменными CEP_ARGON4, CEP_OPENCONNECT,
CEP_OPENVPN, CEP_XRAY и CEP_SING_BOX.`)
}
