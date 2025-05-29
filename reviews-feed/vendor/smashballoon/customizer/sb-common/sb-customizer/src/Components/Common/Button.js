import SbUtils from '../../Utils/SbUtils'
const parse = require('html-react-parser');

const Button = ( props ) => {

    const   slug = 'sb-btn',
            classesList =  [ 'type', 'size' ],
            actionsList =  [ 'onClick' ],
            attributesList = [
                { 'icon-position' : 'left'},
                'full-width',
                'boxshadow',
                'disabled',
                'loading'
            ];

    const createMarkup = ( ) => {
        return { __html: 'text' };
    }

    return (
        <button
            className={ SbUtils.getClassNames( props, slug, classesList ) }
           { ...SbUtils.getElementActions( props, actionsList ) }
           { ...SbUtils.getElementAttributes( props, attributesList ) }
           data-onlyicon={ props.text === undefined }
        >
            { SbUtils.printIcon( props.icon, 'sb-btn-icon', false, props?.iconSize ) }
            { ( props?.text !== undefined ) &&
                <span
                    dangerouslySetInnerHTML={{__html: props?.text.replace(/\s/g, '&nbsp;') }}
                >
                </span>
            }

            {
                props?.tooltip !== undefined &&
                SbUtils.printTooltip( props.tooltip, {
                    type : props?.tooltipType,
                    position : props?.tooltipPosition
                } )
            }
        </button>
    )
}

export default Button;