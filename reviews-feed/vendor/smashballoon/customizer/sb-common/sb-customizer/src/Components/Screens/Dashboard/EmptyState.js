import SbUtils from '../../../Utils/SbUtils'
import { __ } from '@wordpress/i18n'

const EmptyState = () => {

    return (
        <div className='sb-emptystate-ctn sb-fs'>
            <div className="sb-wlcm-inf-1 sb-fs">
                <div className="sb-inf-svg">
                    <svg className="sb-arrow-head" width="13" height="7" viewBox="0 0 13 7" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1 6L5.5 1L11.5 6" stroke="#141B38" strokeWidth="2" strokeLinejoin="round"/>
                    </svg>

                    <svg className="sb-arrow-shaft" width="85" height="62" viewBox="0 0 85 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M84.5 59C63.5 66 4.5 54 1.5 0.5" stroke="#141B38" strokeWidth="2" strokeLinejoin="round"/>
                    </svg>
                </div>
                <div className="sb-inf-cnt">
                    <div className="sb-inf-num"><span>1</span></div>
                    <div className="sb-inf-txt">
                        <h4>{ __( 'Create your feed', 'sb-customizer' ) }</h4>
                        <p className="sb-small-p">{ __( 'Connect your Google, Facebook, Yelp or Tripadvisor account and choose a feed type', 'sb-customizer' ) }</p>
                    </div>
                </div>
            </div>

            <div className="sb-wlcm-inf-2 sb-fs">
                <div className="sb-inf-cnt">
                    <div className="sb-inf-num"><span>2</span></div>
                    <div className="sb-inf-txt">
                        <h4>{ __( 'Customize your feed type', 'sb-customizer' ) }</h4>
                        <p className="sb-small-p">{ __( 'Choose layouts, color schemes, filters and more', 'sb-customizer' ) }</p>
                    </div>
                    <div className="sb-inf-img">
                        { SbUtils.printIcon( 'welcome-1' ) }
                    </div>
                </div>
            </div>

            <div className="sb-wlcm-inf-3 sb-fs">
                <div className="sb-inf-cnt">
                    <div className="sb-inf-img">
                        { SbUtils.printIcon( 'welcome-2' ) }
                    </div>
                    <div className="sb-inf-num"><span>3</span></div>
                    <div className="sb-inf-txt">
                        <h4>{ __( 'Embed your feed', 'sb-customizer' ) }</h4>
                        <p className="sb-small-p">{ __( 'Easily add the feed anywhere on your website', 'sb-customizer' ) }</p>
                    </div>
                </div>
            </div>
        </div>
    )
}

export default EmptyState;