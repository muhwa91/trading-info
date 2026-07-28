Set objShell = CreateObject("WScript.Shell")
Set objFSO = CreateObject("Scripting.FileSystemObject")

' 스크립트(.vbs) 자신이 위치한 폴더를 기준 경로로 사용 → 프로젝트를 옮겨도 경로가 깨지지 않음
strBase = objFSO.GetParentFolderName(WScript.ScriptFullName)
strBackend = strBase & "\backend"
strFrontend = strBase & "\frontend"

' PHP 실행 파일 — 경로는 php_path.txt 한 곳에서만 정의(전 스크립트 공용).
'   PATH 의 php(XAMPP 7.4)로는 artisan 이 실행되지 않으므로 폴백하지 않고 중단한다.
Dim strPhp
strPhp = ""
If objFSO.FileExists(strBase & "\php_path.txt") Then
    strPhp = Trim(objFSO.OpenTextFile(strBase & "\php_path.txt", 1).ReadLine)
End If
If strPhp = "" Or Not objFSO.FileExists(strPhp) Then
    MsgBox "PHP 실행 파일을 찾을 수 없습니다: " & strPhp & vbCrLf & vbCrLf & _
           "세팅 스크립트를 먼저 실행하세요: setup_trading-info.ps1" & vbCrLf & "(그 스크립트가 이 머신의 PHP 8.4+ 경로를 php_path.txt 에 자동 기록합니다.)", 16, "trading-info"
    WScript.Quit 1
End If

Function IsPortOpen(port)
    Dim intResult
    ' netstat 으로 해당 포트가 "LISTENING" 중인지 확인 (창 숨김, 종료까지 대기)
    '   · /c:":8000" 처럼 콜론을 붙여야 함 — /c ":" 8000 은 findstr 가 8000 을 "파일명"으로 읽어
    '     항상 실패(=포트 안 열림)로 판정 → 서버를 매번 중복 기동했음.
    '   · LISTENING 필터 필수 — 접속 종료 후 남는 TIME_WAIT 소켓(127.0.0.1:8000)도 ":8000" 에
    '     매치돼, 서버가 떠 있지 않은데도 "이미 실행 중"으로 오판해 기동을 건너뛰었음.
    intResult = objShell.Run("cmd.exe /c netstat -ano | findstr /c:"":" & port & """ | findstr LISTENING", 0, True)
    ' findstr 가 매치하면 종료코드 0
    If intResult = 0 Then
        IsPortOpen = True
    Else
        IsPortOpen = False
    End If
End Function

' 1. Backend API (Port 8000)
If Not IsPortOpen(8000) Then
    objShell.Run "cmd.exe /c cd /d """ & strBackend & """ && """ & strPhp & """ artisan serve --port=8000", 0, False
End If

' 2. WebSocket Server (Port 8080)
If Not IsPortOpen(8080) Then
    objShell.Run "cmd.exe /c cd /d """ & strBackend & """ && """ & strPhp & """ artisan agent:serve", 0, False
End If

' 3. Frontend Dev Server (Port 5173)
If Not IsPortOpen(5173) Then
    objShell.Run "cmd.exe /c cd /d """ & strFrontend & """ && npm run dev", 0, False
End If

' 서버 기동 대기 (3초)
WScript.Sleep 3000

' 기본 브라우저로 대시보드 열기 (일반 창)
objShell.Run "http://localhost:5173", 1, False
