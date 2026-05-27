/**
 * NotFoundPage — client-side 404 for unknown `/admin/*` SPA routes.
 *
 * The server serves the shell for any `/admin/*` path (so deep links work
 * on reload); the router then resolves the actual view. Unknown
 * sub-routes land here rather than on a blank screen.
 */
import { Link } from 'react-router-dom';

export function NotFoundPage(): JSX.Element {
  return (
    <section className="page page--not-found" aria-labelledby="nf-heading">
      <h1 id="nf-heading">Page not found</h1>
      <p>That admin page does not exist.</p>
      <Link to="/">Back to the dashboard</Link>
    </section>
  );
}
