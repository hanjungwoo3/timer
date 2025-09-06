<?php
session_start();

// 설정 불러오기 (JSON 파일 우선, 없으면 세션, 그것도 없으면 메인 페이지로)
$settings = null;

if (file_exists('timer_settings.json')) {
    $settings = json_decode(file_get_contents('timer_settings.json'), true);
} elseif (isset($_SESSION['timer_settings'])) {
    $settings = $_SESSION['timer_settings'];
}

if (!$settings) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['title']) ?> - 타이머</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="timer-body">
    <div class="timer-container">
        <div class="timer-content">
            <h1 class="timer-title"><?= nl2br($settings['title']) ?></h1>
            
            <div class="timer-display-container">
                <div class="circular-progress">
                    <svg class="progress-ring" viewBox="0 0 400 400">
                        <circle class="progress-ring-background" cx="200" cy="200" r="190" />
                        <circle class="progress-ring-circle" cx="200" cy="200" r="190" />
                    </svg>
                           <div class="timer-display" id="timerDisplay">
                               <?= sprintf('%02d:%02d', $settings['minutes'], isset($settings['seconds']) ? $settings['seconds'] : 0) ?>
                           </div>
                    <div class="music-info" id="musicInfo"></div>
                </div>
            </div>
            
            <div class="timer-controls">
                <button id="fullscreenBtn" class="control-btn" title="전체화면 (F11 또는 Space)">⛶</button>
                <button id="pauseBtn" class="control-btn" title="일시정지/재생 (전체화면에서 Space)">⏸</button>
                <button id="stopBtn" class="control-btn" title="정지 (ESC)">⏹</button>
            </div>
            
            <div id="endMessage" class="end-message" style="display: none;">
                <?= htmlspecialchars($settings['end_message']) ?>
            </div>
        </div>
    </div>
    
    <?php 
    $music_url = isset($settings['online_music']) ? $settings['online_music'] : '';
    if (!empty($music_url)): 
        // 직접 링크 시도 (CDN에서 바로 전송)
        $direct_url = $music_url;
        $proxy_url = 'music_proxy.php?url=' . urlencode($music_url);
    ?>
        <audio id="backgroundMusic" loop preload="auto">
            <!-- 첫 번째 시도: crossorigin 없이 -->
            <source src="<?= htmlspecialchars($direct_url) ?>" type="audio/mpeg">
            브라우저가 오디오를 지원하지 않습니다.
        </audio>
        <script>
            console.log('원본 음악 URL:', <?= json_encode($music_url) ?>);
            console.log('직접 링크 시도:', <?= json_encode($direct_url) ?>);
            console.log('프록시 백업 URL:', <?= json_encode($proxy_url) ?>);
            
            // 다단계 CORS 우회 시도
            const backgroundMusic = document.getElementById('backgroundMusic');
            let attemptCount = 0;
            const maxAttempts = 3;
            
            function tryDirectAccess() {
                attemptCount++;
                console.log(`직접 링크 시도 ${attemptCount}/${maxAttempts}`);
                
                if (backgroundMusic) {
                    // 에러 발생 시 다음 방법 시도
                    backgroundMusic.addEventListener('error', function handleError() {
                        console.log(`시도 ${attemptCount} 실패`);
                        
                        if (attemptCount < maxAttempts) {
                            // 다른 방법으로 재시도
                            this.removeEventListener('error', handleError);
                            
                            if (attemptCount === 2) {
                                // 두 번째 시도: crossorigin 추가
                                console.log('crossorigin 속성 추가하여 재시도');
                                this.crossOrigin = 'anonymous';
                            } else if (attemptCount === 3) {
                                // 세 번째 시도: 프록시 사용
                                console.log('프록시로 전환');
                                this.src = <?= json_encode($proxy_url) ?>;
                            }
                            this.load();
                        } else {
                            console.error('모든 시도 실패');
                        }
                    });
                    
                    // 성공 시 로그
                    backgroundMusic.addEventListener('canplay', function() {
                        if (attemptCount <= 2) {
                            console.log('🎉 CDN 직접 연결 성공! 서버 트래픽 0MB');
                        } else {
                            console.log('⚠️ 프록시 연결 성공 (서버 트래픽 발생)');
                        }
                    });
                    
                    backgroundMusic.addEventListener('loadstart', function() {
                        console.log('음악 로드 시작:', this.src);
                    });
                }
            }
            
            // 첫 번째 시도 시작
            tryDirectAccess();
            
            // 음악 정보 표시
            const musicInfo = document.getElementById('musicInfo');
            if (musicInfo) {
                // JSON에서 음악 제목 찾기
                fetch('music_list.json')
                    .then(response => response.json())
                    .then(data => {
                        const currentSong = data.songs.find(song => song.url === <?= json_encode($music_url) ?>);
                        if (currentSong) {
                            musicInfo.textContent = '♪ ' + currentSong.title;
                        } else {
                            musicInfo.textContent = '♪ 배경음악 재생 중';
                        }
                    })
                    .catch(e => {
                        console.log('음악 정보 로드 실패:', e);
                        musicInfo.textContent = '♪ 배경음악 재생 중';
                    });
            }
        </script>
    <?php else: ?>
        <script>
            console.log('음악이 선택되지 않았습니다.');
            
            // 음악이 없을 때 정보 표시
            const musicInfo = document.getElementById('musicInfo');
            if (musicInfo) {
                musicInfo.textContent = '♪ 음악 없음';
            }
        </script>
    <?php endif; ?>
    
        <script>
            // 타이머 설정
            const TOTAL_SECONDS = <?= ($settings['minutes'] * 60) + (isset($settings['seconds']) ? $settings['seconds'] : 0) ?>;
            const END_MESSAGE = <?= json_encode($settings['end_message']) ?>;
        
        let remainingSeconds = TOTAL_SECONDS;
        let isRunning = true;
        let isPaused = false;
        let timerInterval;
        let blinkInterval;
        
        // DOM 요소
        const timerDisplay = document.getElementById('timerDisplay');
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        const pauseBtn = document.getElementById('pauseBtn');
        const stopBtn = document.getElementById('stopBtn');
        const endMessage = document.getElementById('endMessage');
        const progressRing = document.querySelector('.progress-ring-circle');
        // backgroundMusic은 위에서 이미 선언됨
        
        // 진행바 설정
        const radius = progressRing.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        progressRing.style.strokeDasharray = `${circumference} ${circumference}`;
        progressRing.style.strokeDashoffset = 0; // 초기값을 0으로 설정 (100% 상태)
        
        // 진행바 업데이트 함수 (남은 시간에 따라 원이 줄어듦)
        function setProgress(percent) {
            // percent가 100%일 때 offset = 0 (완전한 원)
            // percent가 0%일 때 offset = circumference (원이 사라짐)
            const offset = circumference * (1 - percent / 100);
            progressRing.style.strokeDashoffset = offset;
            
            // 마지막 10초일 때 색상 변경
            if (remainingSeconds <= 10 && remainingSeconds > 0) {
                progressRing.style.stroke = '#ff8c00'; // 형광 오렌지
            } else {
                progressRing.style.stroke = '#00ffff'; // 시안
            }
        }
        
        // 타이머 디스플레이 업데이트
        function updateDisplay() {
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            // 진행률 계산 및 업데이트
            const progress = (remainingSeconds / TOTAL_SECONDS) * 100;
            console.log(`남은시간: ${remainingSeconds}초, 전체시간: ${TOTAL_SECONDS}초, 진행률: ${progress.toFixed(1)}%`);
            setProgress(progress);
            
            // 깜빡임 애니메이션
            if (isRunning && !isPaused) {
                startBlinkAnimation();
            }
        }
        
        // 깜빡임 애니메이션 (크기를 더 작게 조정)
        function startBlinkAnimation() {
            timerDisplay.style.transform = 'translate(-50%, -50%) scale(1.03)'; // translate 유지하면서 scale
            timerDisplay.style.fontWeight = '900';
            
            // 마지막 10초일 때는 주황색, 아니면 시안색
            const animationColor = (remainingSeconds <= 10 && remainingSeconds > 0) ? '#ff8c00' : '#00ffff';
            timerDisplay.style.color = animationColor;
            
            setTimeout(() => {
                timerDisplay.style.transform = 'translate(-50%, -50%) scale(1)';
                timerDisplay.style.fontWeight = 'bold';
                timerDisplay.style.color = '#ffffff'; // 원래 흰색으로 복원
            }, 200);
        }
        
        // 전체화면 해제 함수
        function exitFullscreen() {
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                
                if (document.exitFullscreen) {
                    document.exitFullscreen().then(() => {
                        console.log('전체화면 해제 완료');
                    }).catch(err => {
                        console.log('전체화면 해제 실패:', err);
                    });
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }

        // 타이머 메인 로직
        function startTimer() {
            // 이미 실행 중인 타이머가 있으면 중지
            if (timerInterval) {
                clearInterval(timerInterval);
                console.log('기존 타이머 중지됨');
            }
            
            timerInterval = setInterval(() => {
                if (isRunning && !isPaused) {
                    remainingSeconds--;
                    updateDisplay();
                    
                    if (remainingSeconds <= 0) {
                        timerFinished();
                    }
                }
            }, 1000);
            console.log('새 타이머 시작됨');
        }
        
        // 타이머 완료
        function timerFinished() {
            clearInterval(timerInterval);
            isRunning = false;
            
            // 음악 정지
            if (backgroundMusic) {
                backgroundMusic.pause();
            }
            
            // 진행바 숨기기
            document.querySelector('.circular-progress').style.display = 'none';
            
            // 컨트롤 버튼들 숨기기
            document.querySelector('.timer-controls').style.display = 'none';
            
            // 종료 메시지 표시
            endMessage.style.display = 'block';
            
            // 종료 메시지 표시 후 전체화면 상태 유지
            console.log('타이머 완료 - 종료 메시지 표시 중, 전체화면 유지');
            
            // 종료 후에도 ESC 키로 설정 페이지로 이동 가능하도록 안내 추가
            setTimeout(() => {
                const instructionDiv = document.createElement('div');
                instructionDiv.style.cssText = `
                    position: fixed;
                    bottom: 50px;
                    left: 50%;
                    transform: translateX(-50%);
                    color: #888888;
                    font-size: 18px;
                    text-align: center;
                    z-index: 1000;
                `;
                instructionDiv.innerHTML = 'ESC 키를 눌러 설정 페이지로 돌아가기';
                document.body.appendChild(instructionDiv);
            }, 2000);
        }
        
        // 일시정지/재생 토글
        function togglePause() {
            isPaused = !isPaused;
            pauseBtn.textContent = isPaused ? '▶' : '⏸';
            
            if (backgroundMusic) {
                if (isPaused) {
                    backgroundMusic.pause();
                } else {
                    backgroundMusic.play();
                }
            }
        }
        
        // 타이머 정지 (수동 정지 - 트레이로 보내지 않음)
        function stopTimer() {
            clearInterval(timerInterval);
            isRunning = false;
            
            if (backgroundMusic) {
                backgroundMusic.pause();
            }
            
            // 전체화면 해제 후 설정 페이지로 이동
            exitFullscreen();
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 300);
        }
        
        // 이벤트 리스너
        fullscreenBtn.addEventListener('click', toggleFullscreen);
        pauseBtn.addEventListener('click', togglePause);
        stopBtn.addEventListener('click', stopTimer);
        
        // 키보드 단축키
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                // 타이머가 완료된 상태인지 확인
                if (!isRunning && endMessage.style.display === 'block') {
                    // 타이머 완료 후 ESC: 설정 페이지로 이동
                    exitFullscreen();
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 300);
                } else {
                    // 타이머 실행 중 ESC: 일반 정지
                    stopTimer();
                }
            } else if (e.key === ' ') {
                e.preventDefault();
                // 전체화면이 아니면 전체화면 전환, 전체화면이면 일시정지/재생
                if (!document.fullscreenElement && 
                    !document.webkitFullscreenElement && 
                    !document.mozFullScreenElement && 
                    !document.msFullscreenElement) {
                    toggleFullscreen();
                } else {
                    togglePause();
                }
            } else if (e.key === 'F11') {
                e.preventDefault();
                toggleFullscreen();
            }
        });
        
        // 초기화 및 시작
        updateDisplay(); // 초기 디스플레이 설정
        startTimer();    // 타이머 시작
        
        // 음악 재생 시작
        if (backgroundMusic) {
            console.log('음악 요소 발견:', backgroundMusic.src);
            
            // 음악 상태 이벤트 리스너들
            backgroundMusic.addEventListener('loadstart', () => {
                console.log('음악 로드 시작');
            });
            
            backgroundMusic.addEventListener('loadeddata', () => {
                console.log('음악 데이터 로드됨');
            });
            
            backgroundMusic.addEventListener('canplay', () => {
                console.log('음악 재생 가능');
                // 즉시 재생 시도
                tryPlayMusic();
            });
            
            backgroundMusic.addEventListener('canplaythrough', () => {
                console.log('음악 완전 로드됨');
                tryPlayMusic();
            });
            
            backgroundMusic.addEventListener('error', (e) => {
                console.error('음악 로드 오류:', e);
                console.error('오류 코드:', backgroundMusic.error?.code);
                console.error('오류 메시지:', backgroundMusic.error?.message);
            });
            
            backgroundMusic.addEventListener('play', () => {
                console.log('음악 재생 시작됨');
            });
            
            backgroundMusic.addEventListener('pause', () => {
                console.log('음악 일시정지됨');
            });
            
            // 재생 시도 함수
            function tryPlayMusic() {
                if (backgroundMusic.readyState >= 2) { // HAVE_CURRENT_DATA
                    backgroundMusic.play().then(() => {
                        console.log('음악 재생 성공');
                    }).catch(e => {
                        console.log('음악 자동 재생 차단:', e.message);
                        showMusicPlayButton();
                    });
                }
            }
            
            // 수동 재생 버튼 표시
            function showMusicPlayButton() {
                const playButton = document.createElement('button');
                playButton.textContent = '🎵 음악 재생';
                playButton.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: #00ffff;
                    color: #000;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 5px;
                    cursor: pointer;
                    z-index: 1000;
                    font-size: 14px;
                `;
                
                playButton.onclick = () => {
                    backgroundMusic.play().then(() => {
                        console.log('수동 음악 재생 성공');
                        playButton.remove();
                    }).catch(err => {
                        console.error('수동 음악 재생 실패:', err);
                    });
                };
                
                document.body.appendChild(playButton);
                
                // 5초 후 자동 제거
                setTimeout(() => {
                    if (playButton.parentNode) {
                        playButton.remove();
                    }
                }, 5000);
            }
            
            // 음악 로드 시작
            backgroundMusic.load();
        } else {
            console.log('음악 요소가 없습니다.');
        }
        
        // 전체화면 토글
        function toggleFullscreen() {
            if (!document.fullscreenElement && 
                !document.webkitFullscreenElement && 
                !document.mozFullScreenElement && 
                !document.msFullscreenElement) {
                // 전체화면 진입
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(e => {
                        console.log('전체화면 모드를 지원하지 않습니다:', e);
                        alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                    });
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen();
                } else if (document.documentElement.mozRequestFullScreen) {
                    document.documentElement.mozRequestFullScreen();
                } else if (document.documentElement.msRequestFullscreen) {
                    document.documentElement.msRequestFullscreen();
                } else {
                    alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                }
            } else {
                // 전체화면 해제
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }
        
        // 전체화면 상태 변화 감지
        function updateFullscreenButton() {
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                fullscreenBtn.innerHTML = '⛶';
                fullscreenBtn.title = '전체화면 해제 (F11)';
            } else {
                fullscreenBtn.innerHTML = '⛶';
                fullscreenBtn.title = '전체화면 (F11)';
            }
        }
        
        // 전체화면 상태 변화 이벤트 리스너
        document.addEventListener('fullscreenchange', updateFullscreenButton);
        document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
        document.addEventListener('mozfullscreenchange', updateFullscreenButton);
        document.addEventListener('MSFullscreenChange', updateFullscreenButton);
        
        // 페이지 로드 후 자동 시작
        document.addEventListener('DOMContentLoaded', () => {
            updateFullscreenButton();
            updateDisplay();
            
            // 페이지 로드 즉시 전체화면 시도 및 타이머 자동 시작
            setTimeout(() => {
                // 전체화면 시도 (페이지 이동으로 해제되었을 가능성)
                attemptFullscreenAndStartTimer();
            }, 100);
        });
        
        // 전체화면 시도 및 타이머 시작
        function attemptFullscreenAndStartTimer() {
            const element = document.documentElement;
            
            // 이미 전체화면인지 확인
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                console.log('이미 전체화면 상태, 타이머 시작');
                startTimerNow();
                return;
            }
            
            // 전체화면 요청
            let fullscreenPromise;
            if (element.requestFullscreen) {
                fullscreenPromise = element.requestFullscreen();
            } else if (element.webkitRequestFullscreen) {
                fullscreenPromise = element.webkitRequestFullscreen();
            } else if (element.mozRequestFullScreen) {
                fullscreenPromise = element.mozRequestFullScreen();
            } else if (element.msRequestFullscreen) {
                fullscreenPromise = element.msRequestFullscreen();
            }
            
            if (fullscreenPromise) {
                fullscreenPromise.then(() => {
                    console.log('타이머 페이지에서 전체화면 전환 성공');
                    startTimerNow();
                }).catch((error) => {
                    console.log('타이머 페이지에서 전체화면 전환 실패:', error);
                    // 전체화면 실패 시에만 안내 표시
                    if (!document.fullscreenElement && 
                        !document.webkitFullscreenElement && 
                        !document.mozFullScreenElement && 
                        !document.msFullscreenElement) {
                        showFullscreenPrompt();
                    }
                    startTimerNow();
                });
            } else {
                console.log('전체화면 API 지원 안함');
                // 전체화면 API가 없고 현재 전체화면이 아닌 경우에만 안내 표시
                if (!document.fullscreenElement && 
                    !document.webkitFullscreenElement && 
                    !document.mozFullScreenElement && 
                    !document.msFullscreenElement) {
                    showFullscreenPrompt();
                }
                startTimerNow();
            }
        }
        
        // 타이머 즉시 시작
        function startTimerNow() {
            // 이미 실행 중이면 중복 시작 방지
            if (isRunning) {
                console.log('타이머가 이미 실행 중입니다');
                return;
            }
            
            isRunning = true;
            startTimer();
            console.log('타이머 자동 시작됨');
        }
        
        // 전체화면 안내 표시 (비활성화)
        function showFullscreenPrompt() {
            // 깜빡임 방지를 위해 함수 비활성화
            console.log('전체화면 안내 표시 요청됨 (비활성화됨)');
            // 더 이상 버튼 깜빡임 없음
        }
        
    </script>
</body>
</html>
