import { useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';

export default function Welcome() {
  const navigate = useNavigate();
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const appVersion = 'v1.0.0';

  useEffect(() => {
    // Verificar si el usuario está autenticado
    const checkAuth = async () => {
      try {
        const response = await fetch('/api/user', {
          headers: {
            'Accept': 'application/json',
          },
        });
        if (response.ok) {
          setIsAuthenticated(true);
        }
      } catch {
        setIsAuthenticated(false);
      }
    };
    
    checkAuth();
  }, []);

  const handleNavigation = (path) => {
    navigate(path);
  };

  return (
    <div className="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
      {/* Header with Navigation */}
      <header className="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6 flex items-center justify-between">
        <img src="/logo-fenix.png" alt="Fénix" className="h-8 w-8 object-contain" />
        {!isAuthenticated ? (
          <nav className="flex items-center justify-end gap-4">
            <a
              onClick={() => handleNavigation('/login')}
              className="inline-block px-5 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal cursor-pointer"
            >
              Ingresar
            </a>
            <a
              onClick={() => handleNavigation('/bienvenida')}
              className="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal cursor-pointer"
            >
              Registrarse
            </a>
          </nav>
        ) : (
          <nav className="flex items-center justify-end gap-4">
            <a
              onClick={() => handleNavigation('/bienvenida')}
              className="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal cursor-pointer"
            >
              Panel
            </a>
          </nav>
        )}
      </header>

      {/* Main Content */}
      <div className="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow starting:opacity-0">
        <main className="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row">
          {/* Left Content */}
          <div className="text-[13px] leading-[20px] flex-1 p-6 pb-6 lg:p-20 lg:pb-10 bg-white dark:bg-[#161615] dark:text-[#EDEDEC] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
            <h1 className="mb-1 font-medium text-lg">Bienvenido a Logix</h1>
            <p className="mb-2 text-[#706f6c] dark:text-[#A1A09A]">
              Sistema de gestión integral para tu negocio.<br />
              Aquí puedes gestionar tus operaciones de forma eficiente.
            </p>

            <ul className="flex flex-col mb-4 lg:mb-6">
              <li className="flex items-center gap-4 py-2">
                <span className="relative py-1 bg-white dark:bg-[#161615]">
                  <span className="flex items-center justify-center rounded-full bg-[#FDFDFC] dark:bg-[#161615] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)] w-3.5 h-3.5 border dark:border-[#3E3E3A] border-[#e3e3e0]">
                    <span className="rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A] w-1.5 h-1.5"></span>
                  </span>
                </span>
                <span>
                  Accede a{' '}
                  <a
                    onClick={() => handleNavigation('/login')}
                    className="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1 cursor-pointer"
                  >
                    <span>Tu panel de control</span>
                    <svg
                      width="10"
                      height="11"
                      viewBox="0 0 10 11"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                      className="w-2.5 h-2.5"
                    >
                      <path
                        d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001"
                        stroke="currentColor"
                        strokeLinecap="square"
                      />
                    </svg>
                  </a>
                </span>
              </li>
            </ul>

            <ul className="flex gap-3 text-sm leading-normal">
              <li>
                <button
                  onClick={() => handleNavigation('/bienvenida')}
                  className="inline-block dark:bg-[#eeeeec] dark:border-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white dark:hover:border-white hover:bg-black hover:border-black px-5 py-1.5 bg-[#1b1b18] rounded-sm border border-black text-white text-sm leading-normal"
                >
                  Comenzar ahora
                </button>
              </li>
            </ul>

            <p className="mt-6 lg:mt-10 text-[#706f6c] dark:text-[#A1A09A]">{appVersion}</p>
          </div>

          {/* Right Side - Logo Section */}
          <div className="bg-[#fff2f2] dark:bg-[#1D0002] relative lg:-ml-px -mb-px lg:mb-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg aspect-[335/364] lg:aspect-auto w-full lg:w-[438px] shrink-0 overflow-hidden">
            <div className="flex items-center justify-center h-full w-full p-8">
              <img src="/Imagens/Fenix.png" alt="Fénix" className="max-h-full max-w-full object-contain" />
            </div>

            {/* Decorative Border */}
            <div className="absolute inset-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"></div>
          </div>
        </main>
      </div>
    </div>
  );
}
